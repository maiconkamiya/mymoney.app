<script setup lang="ts">
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { agentLabels, money, percent } from '@/lib/trading';
import type { AgentScoreRow, Performance, TaxMonth } from '@/types';

const props = defineProps<{
    portfolioId: number;
    market: string;
    performance: Performance;
    agentScores: AgentScoreRow[];
    taxMonths: TaxMonth[];
}>();

const width = 600;
const height = 160;

const path = computed(() => {
    const curve = props.performance.curve;
    const values = curve.flatMap((p) =>
        p.benchmark === null ? [p.equity] : [p.equity, p.benchmark],
    );
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const x = (i: number) =>
        curve.length < 2 ? width / 2 : (i / (curve.length - 1)) * width;
    const y = (v: number) => height - ((v - min) / span) * height;
    const line = (pick: (p: (typeof curve)[number]) => number | null) =>
        curve
            .map((p, i) => ({ i, v: pick(p) }))
            .filter((p): p is { i: number; v: number } => p.v !== null)
            .map((p, n) => `${n === 0 ? 'M' : 'L'}${x(p.i)},${y(p.v)}`)
            .join(' ');

    return {
        equity: line((p) => p.equity),
        benchmark: line((p) => p.benchmark),
    };
});

const taxUrl = computed(
    () => `/portfolios/${props.portfolioId}/reports/tax.csv`,
);
</script>

<template>
    <section class="space-y-4">
        <Heading
            variant="small"
            title="Desempenho"
            description="Curva de patrimônio a partir dos snapshots diários, comparada ao benchmark."
        />

        <div class="grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Retorno</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ percent(performance.return) }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">
                    vs {{ performance.benchmark ?? 'benchmark' }}
                </p>
                <p class="text-xl font-semibold tabular-nums">
                    {{
                        performance.excess_return === null
                            ? '—'
                            : percent(performance.excess_return)
                    }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Drawdown máximo</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ percent(-performance.max_drawdown) }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Sharpe</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ performance.sharpe?.toFixed(2) ?? '—' }}
                </p>
            </div>
        </div>

        <div class="rounded-xl border p-4">
            <svg
                :viewBox="`0 0 ${width} ${height}`"
                class="h-40 w-full"
                preserveAspectRatio="none"
                role="img"
                aria-label="Curva de patrimônio"
            >
                <path
                    v-if="path.benchmark"
                    :d="path.benchmark"
                    fill="none"
                    class="stroke-muted-foreground"
                    stroke-dasharray="4 3"
                    stroke-width="1.5"
                    vector-effect="non-scaling-stroke"
                />
                <path
                    :d="path.equity"
                    fill="none"
                    class="stroke-primary"
                    stroke-width="2"
                    vector-effect="non-scaling-stroke"
                />
            </svg>
            <p class="mt-2 text-xs text-muted-foreground">
                Linha cheia: carteira · tracejada:
                {{ performance.benchmark ?? 'benchmark' }} (sem dados de
                benchmark, só a carteira aparece).
            </p>
        </div>

        <Heading
            variant="small"
            title="Placar dos agentes"
            description="Taxa de acerto das chamadas de compra e venda; o peso entra no consenso (1,00 até 10 sinais)."
        />
        <p
            v-if="agentScores.length === 0"
            class="text-sm text-muted-foreground"
        >
            O placar é calculado todo dia depois que há sinais executados.
        </p>
        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Agente</th>
                        <th class="px-4 py-2 text-right font-medium">Sinais</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Acertos
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Taxa</th>
                        <th class="px-4 py-2 text-right font-medium">Peso</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in agentScores"
                        :key="row.agent"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ agentLabels[row.agent] ?? row.agent }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ row.signals }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ row.hits }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ (row.hit_rate * 100).toFixed(1) }}%
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ row.weight.toFixed(2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between">
            <Heading
                variant="small"
                title="Imposto de renda"
                description="Resultado realizado por mês (custo médio, taxas incluídas)."
            />
            <a :href="taxUrl" class="text-sm underline" download
                >Exportar CSV</a
            >
        </div>
        <p v-if="taxMonths.length === 0" class="text-sm text-muted-foreground">
            Sem operações executadas.
        </p>
        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Mês</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Compras
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Vendas</th>
                        <th class="px-4 py-2 text-right font-medium">Taxas</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Resultado
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in taxMonths" :key="m.month" class="border-t">
                        <td class="px-4 py-2 font-medium">{{ m.month }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ money(m.bought, market) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ money(m.sold, market) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ money(m.fees, market) }}
                        </td>
                        <td
                            class="px-4 py-2 text-right tabular-nums"
                            :class="m.result < 0 ? 'text-destructive' : ''"
                        >
                            {{ money(m.result, market) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
