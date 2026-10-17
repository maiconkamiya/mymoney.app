<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import SignalDecisionController from '@/actions/App/Http/Controllers/SignalDecisionController';
import Heading from '@/components/Heading.vue';
import AssetChartsCard from '@/components/portfolio/AssetChartsCard.vue';
import AgentSwarmCard from '@/components/portfolio/AgentSwarmCard.vue';
import ChartReading from '@/components/portfolio/ChartReading.vue';
import SignalCalculation from '@/components/portfolio/SignalCalculation.vue';
import PerformancePanel from '@/components/portfolio/PerformancePanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    actionLabels,
    agentLabels,
    marketLabels,
    money,
    percent,
    stageLabels,
} from '@/lib/trading';
import { index, update } from '@/routes/portfolios';
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

const stageVariant = (stage: string) =>
    stage === 'approved'
        ? 'default'
        : stage === 'rejected'
          ? 'destructive'
          : 'secondary';
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
                description="Cada sinal mostra o voto final de cada agente. Sinais aprovados esperam sua confirmação."
            />

            <p
                v-if="signals.length === 0"
                class="mt-2 text-sm text-muted-foreground"
            >
                Nenhum sinal ainda. Eles aparecem quando o scanner ou uma
                notícia relevante dispara uma rodada de agentes.
            </p>

            <div class="mt-2 space-y-4">
                <article
                    v-for="signal in signals"
                    :key="signal.id"
                    class="rounded-2xl border bg-card p-4"
                >
                    <header class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ signal.symbol }}</span>
                        <Badge :variant="stageVariant(signal.stage)">
                            {{ stageLabels[signal.stage] ?? signal.stage }}
                        </Badge>
                        <span
                            v-if="signal.side"
                            class="text-sm text-muted-foreground"
                        >
                            {{ actionLabels[signal.side] }} · score
                            {{ signal.score?.toFixed(2) }}
                        </span>
                        <span class="ml-auto text-xs text-muted-foreground">
                            {{
                                new Date(signal.created_at).toLocaleString(
                                    'pt-BR',
                                )
                            }}
                            · custo US$ {{ signal.cost.toFixed(4) }}
                        </span>
                    </header>

                    <p
                        v-if="signal.reason"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ signal.reason }}
                    </p>

                    <p
                        v-if="signal.entry_price"
                        class="mt-1 text-sm tabular-nums"
                    >
                        Entrada
                        {{ money(signal.entry_price, portfolio.market) }} · stop
                        {{ money(signal.stop_price, portfolio.market) }} · alvo
                        {{ money(signal.target_price, portfolio.market) }}
                    </p>

                    <SignalCalculation
                        v-if="signal.calculation"
                        :calculation="signal.calculation"
                        :reason="signal.reason"
                    />

                    <ul
                        v-if="signal.votes.length"
                        class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <li
                            v-for="vote in signal.votes"
                            :key="vote.agent"
                            class="rounded-lg bg-muted/40 p-3 text-sm"
                        >
                            <p class="font-medium">
                                {{ agentLabels[vote.agent] ?? vote.agent }}:
                                <template v-if="vote.abstained">
                                    Absteve-se
                                </template>
                                <template v-else>
                                    {{ actionLabels[vote.action] }}
                                    {{ Math.round(vote.confidence * 100) }}%
                                </template>
                                <span v-if="vote.veto" class="text-destructive">
                                    · veto
                                </span>
                            </p>
                            <p class="text-muted-foreground">
                                {{ vote.reasons.join(' ') }}
                            </p>
                            <ChartReading
                                v-if="vote.analysis && !vote.abstained"
                                :analysis="vote.analysis"
                                :market="portfolio.market"
                            />
                        </li>
                    </ul>

                    <div
                        v-if="signal.stage === 'approved'"
                        class="mt-3 flex gap-2"
                    >
                        <Form
                            v-bind="
                                SignalDecisionController.confirm.form(signal.id)
                            "
                            v-slot="{ processing }"
                        >
                            <Button size="sm" :disabled="processing">
                                Confirmar e executar
                            </Button>
                        </Form>
                        <Form
                            v-bind="
                                SignalDecisionController.reject.form(signal.id)
                            "
                            v-slot="{ processing }"
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="processing"
                            >
                                Descartar
                            </Button>
                        </Form>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>
