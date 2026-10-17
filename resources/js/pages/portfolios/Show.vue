<script setup lang="ts">
import { echoIsConfigured, useEcho } from '@laravel/echo-vue';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount } from 'vue';
import Heading from '@/components/Heading.vue';
import AssetChartsCard from '@/components/portfolio/AssetChartsCard.vue';
import AgentSwarmCard from '@/components/portfolio/AgentSwarmCard.vue';
import SignalCard from '@/components/portfolio/SignalCard.vue';
import PerformancePanel from '@/components/portfolio/PerformancePanel.vue';
import { Button } from '@/components/ui/button';
import { marketLabels, money, percent } from '@/lib/trading';
import { index, update } from '@/routes/portfolios';
import { signals as portfolioSignals } from '@/routes/portfolios';
import type {
    AgentActivity,
    AgentScoreRow,
    Performance,
    PortfolioCharts,
    PortfolioSummary,
    PositionRow,
    SignalRow,
    TaxMonth,
} from '@/types';

const props = defineProps<{
    portfolio: PortfolioSummary;
    positions: PositionRow[];
    signals: SignalRow[];
    signals_total: number;
    performance: Performance;
    agent_scores: AgentScoreRow[];
    tax_months: TaxMonth[];
    agent_activity: AgentActivity;
    charts: PortfolioCharts;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carteiras', href: index() }],
    },
});

const result = computed(
    () => props.portfolio.equity / props.portfolio.initial_cash - 1,
);

// Os cards de sinais acompanham o ciclo dos agentes (canal privado da carteira):
// um sinal novo, o consenso decidido e a ordem concluída recarregam só a lista.
const reloadingEvents = [
    'cycle.started',
    'consensus.decided',
    'order.finished',
];
let reloadTimer: ReturnType<typeof setTimeout> | undefined;

if (echoIsConfigured()) {
    useEcho<{ type: string }>(
        `portfolios.${props.portfolio.id}.agents`,
        '.agent.activity',
        (event) => {
            if (!reloadingEvents.includes(event.type)) {
                return;
            }

            clearTimeout(reloadTimer);
            reloadTimer = setTimeout(
                () =>
                    router.reload({
                        only: ['signals', 'signals_total'],
                    }),
                600,
            );
        },
    );
}

onBeforeUnmount(() => clearTimeout(reloadTimer));
</script>

<template>
    <Head :title="portfolio.name" />

    <div
        class="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8"
    >
        <Heading
            :title="portfolio.name"
            :description="`${marketLabels[portfolio.market]} · ${portfolio.mode === 'paper' ? 'simulada' : 'real'} · ${portfolio.starts_at} a ${portfolio.ends_at} · ${portfolio.auto_execute ? 'execução automática' : 'aprovação manual'}`"
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Patrimônio</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ money(portfolio.equity, portfolio.market) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Caixa</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ money(portfolio.cash, portfolio.market) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    Resultado sobre
                    {{ money(portfolio.initial_cash, portfolio.market) }}
                </p>
                <p
                    class="text-2xl font-semibold tabular-nums"
                    :class="result < 0 ? 'text-down' : 'text-up'"
                >
                    {{ percent(result) }}
                </p>
            </div>
        </div>

        <section class="rounded-2xl border bg-card p-4">
            <Form
                v-bind="update.form(portfolio.id)"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="auto_execute" value="0" />
                    <input
                        type="checkbox"
                        name="auto_execute"
                        value="1"
                        class="mt-0.5"
                        :checked="portfolio.auto_execute"
                        :disabled="portfolio.mode !== 'paper'"
                    />
                    <span>
                        Executar sinais aprovados sem confirmação manual
                        <span class="block text-muted-foreground">
                            {{
                                portfolio.mode === 'paper'
                                    ? 'Desligado, cada sinal aprovado pelos agentes espera a sua confirmação. A guarda de risco vale nos dois casos.'
                                    : 'Carteira real: cada ordem exige a sua aprovação e a execução automática não pode ser ligada.'
                            }}
                        </span>
                        <span
                            v-if="errors.auto_execute"
                            class="block text-destructive"
                        >
                            {{ errors.auto_execute }}
                        </span>
                    </span>
                </label>
                <Button
                    v-if="portfolio.mode === 'paper'"
                    :disabled="processing"
                    variant="outline"
                >
                    Salvar
                </Button>
            </Form>
        </section>

        <AssetChartsCard
            :portfolio-id="portfolio.id"
            :market="portfolio.market"
            :initial="charts"
        />

        <AgentSwarmCard
            :portfolio-id="portfolio.id"
            :initial="agent_activity"
        />

        <PerformancePanel
            :portfolio-id="portfolio.id"
            :market="portfolio.market"
            :performance="performance"
            :agent-scores="agent_scores"
            :tax-months="tax_months"
        />

        <section>
            <Heading variant="small" title="Posições abertas" />
            <p
                v-if="positions.length === 0"
                class="mt-2 text-sm text-muted-foreground"
            >
                Nenhuma posição aberta.
            </p>
            <div v-else class="mt-2 overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Ativo</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Quantidade
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Preço médio
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Stop
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="position in positions"
                            :key="position.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 font-medium">
                                {{ position.symbol }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ position.qty }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{
                                    money(position.avg_price, portfolio.market)
                                }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{
                                    money(position.stop_price, portfolio.market)
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <Heading
                variant="small"
                title="Sinais"
                description="O sinal mais recente de cada ativo. Abra o card para ver os votos e o cálculo do score. Sinais aprovados esperam sua confirmação."
            />

            <p
                v-if="signals.length === 0"
                class="mt-2 text-sm text-muted-foreground"
            >
                Nenhum sinal ainda. Eles aparecem quando o scanner ou uma
                notícia relevante dispara uma rodada de agentes.
            </p>

            <div class="mt-2 space-y-3">
                <SignalCard
                    v-for="(signal, index) in signals"
                    :key="signal.id"
                    :signal="signal"
                    :market="portfolio.market"
                    :default-open="index === 0"
                />
            </div>

            <Link
                v-if="signals_total > signals.length"
                :href="portfolioSignals(portfolio.id)"
                class="mt-4 inline-block text-sm underline underline-offset-4"
            >
                Ver todos os sinais ({{ signals_total }})
            </Link>
        </section>
    </div>
</template>
