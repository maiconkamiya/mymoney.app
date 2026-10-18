<script setup lang="ts">
import type { ApexAxisChartSeries, ApexOptions } from 'apexcharts';
import { computed } from 'vue';
import ApexChart from '@/components/charts/ApexChart.vue';
import Heading from '@/components/Heading.vue';
import { baseOptions, mergeOptions, useChartPalette } from '@/lib/charts';
import { agentLabels, money, percent } from '@/lib/trading';
import type { AgentScoreRow, Performance, TaxMonth } from '@/types';

const props = defineProps<{
    portfolioId: number;
    market: string;
    performance: Performance;
    agentScores: AgentScoreRow[];
    taxMonths: TaxMonth[];
}>();

const palette = useChartPalette();

const options = computed<ApexOptions>(() => {
    const p = palette.value;

    return mergeOptions(baseOptions(p), {
        chart: { type: 'line' },
        colors: [p.primary, p.muted],
        stroke: { width: [2, 1.5], dashArray: [0, 4] },
        markers: { size: 0, hover: { size: 3 } },
        xaxis: {
            labels: {
                formatter: (value: string | number) =>
                    new Date(Number(value)).toLocaleDateString('pt-BR', {
                        day: '2-digit',
                        month: '2-digit',
                    }),
            },
        },
        yaxis: {
            tickAmount: 3,
            labels: {
                formatter: (value: number) => money(value, props.market),
            },
        },
        tooltip: {
            shared: true,
            x: {
                formatter: (value: number) =>
                    new Date(value).toLocaleDateString('pt-BR'),
            },
            y: { formatter: (value: number) => money(value, props.market) },
        },
    });
});

/** Datas dos snapshots ao meio-dia local, para o eixo não pular um dia pelo fuso. */
const at = (date: string) =>
    new Date(`${date.slice(0, 10)}T12:00:00`).getTime();

const series = computed<ApexAxisChartSeries>(() => {
    // Em ordem de data: o eixo é de tempo, um ponto fora de ordem riscaria o gráfico de volta.
    const curve = [...props.performance.curve].sort((a, b) =>
        a.date.localeCompare(b.date),
    );
    const out: ApexAxisChartSeries = [
        {
            name: 'Carteira',
            data: curve.map((point) => [at(point.date), point.equity]),
        },
    ];

    if (curve.some((point) => point.benchmark !== null)) {
        out.push({
            name: props.performance.benchmark ?? 'Benchmark',
            data: curve.map((point) => [at(point.date), point.benchmark]),
        });
    }

    return out;
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
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Retorno</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ percent(performance.return) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    vs {{ performance.benchmark ?? 'benchmark' }}
                </p>
                <p class="text-xl font-semibold tabular-nums">
                    {{
                        performance.excess_return === null
                            ? '-'
                            : percent(performance.excess_return)
                    }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Drawdown máximo</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ percent(-performance.max_drawdown) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Sharpe</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ performance.sharpe?.toFixed(2) ?? '-' }}
                </p>
            </div>
        </div>

        <div class="rounded-2xl border bg-card p-4">
            <ApexChart
                v-if="performance.curve.length >= 2"
                :options="options"
                :series="series"
                :height="160"
                label="Curva de patrimônio"
            />
            <div
                v-else
                class="flex h-40 items-center justify-center rounded-xl bg-muted/40 text-xs text-muted-foreground"
            >
                A curva aparece depois do segundo snapshot diário.
            </div>
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
        <div v-else class="overflow-x-auto rounded-2xl border bg-card">
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
        <div v-else class="overflow-x-auto rounded-2xl border bg-card">
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
                            :class="m.result < 0 ? 'text-down' : 'text-up'"
                        >
                            {{ money(m.result, market) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
