<script setup lang="ts">
import { echoIsConfigured, useEcho } from '@laravel/echo-vue';
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BinanceController from '@/actions/App/Http/Controllers/Settings/BinanceController';
import { toast } from 'vue-sonner';
import SignalDecisionController from '@/actions/App/Http/Controllers/SignalDecisionController';
import Heading from '@/components/Heading.vue';
import AssetChartsCard from '@/components/portfolio/AssetChartsCard.vue';
import AgentSwarmCard from '@/components/portfolio/AgentSwarmCard.vue';
import ChartReading from '@/components/portfolio/ChartReading.vue';
import TrackedAssetsCard from '@/components/portfolio/TrackedAssetsCard.vue';
import LiveExecutionCard from '@/components/portfolio/LiveExecutionCard.vue';
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
    AccountSync,
    AgentActivity,
    AgentScoreRow,
    AvailableAsset,
    LiveInfo,
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
    account_sync: AccountSync | null;
    signals: SignalRow[];
    performance: Performance;
    agent_scores: AgentScoreRow[];
    tax_months: TaxMonth[];
    agent_activity: AgentActivity;
    charts: PortfolioCharts;
    available_assets: AvailableAsset[];
    tracked_asset_ids: number[];
    live: LiveInfo | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carteiras', href: index() }],
    },
});

const result = computed(
    () => props.portfolio.equity / props.portfolio.initial_cash - 1,
);

type LiveOrderEvent = {
    portfolio_id: number;
    kind: 'sent' | 'failed' | 'refused' | 'panic';
    message: string;
};

// Real-money notices arrive on the owner's private channel the moment they happen.
if (echoIsConfigured()) {
    const userId = usePage().props.auth.user.id;

    useEcho<LiveOrderEvent>(
        `App.Models.User.${userId}`,
        '.live.order',
        (event) => {
            if (event.portfolio_id !== props.portfolio.id) {
                return;
            }

            const show =
                event.kind === 'sent'
                    ? toast.success
                    : event.kind === 'panic'
                      ? toast.warning
                      : toast.error;
            show(event.message);
            router.reload({
                only: ['portfolio', 'live', 'positions', 'signals'],
            });
        },
    );
}

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

        <section
            v-if="portfolio.mode !== 'live'"
            class="rounded-2xl border bg-card p-4"
        >
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
                    />
                    <span>
                        Executar sinais aprovados sem confirmação manual
                        <span class="block text-muted-foreground">
                            Desligado, cada sinal aprovado pelos agentes espera
                            a sua confirmação. A guarda de risco vale nos dois
                            casos.
                        </span>
                        <span
                            v-if="errors.auto_execute"
                            class="block text-destructive"
                        >
                            {{ errors.auto_execute }}
                        </span>
                    </span>
                </label>
                <Button :disabled="processing" variant="outline">
                    Salvar
                </Button>
            </Form>
        </section>

        <TrackedAssetsCard
            :portfolio-id="portfolio.id"
            :available="available_assets"
            :tracked="tracked_asset_ids"
        />

        <LiveExecutionCard
            v-if="portfolio.mode === 'live' && live"
            :portfolio="portfolio"
            :live="live"
        />

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
                                Valor (USDT)
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
                                    position.value === null
                                        ? '-'
                                        : money(
                                              position.value,
                                              portfolio.market,
                                          )
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

            <div
                v-if="account_sync"
                class="mt-3 space-y-2 text-sm text-muted-foreground"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <span>
                        Última sincronização com a Binance:
                        {{
                            account_sync.synced_at
                                ? new Date(
                                      account_sync.synced_at,
                                  ).toLocaleString('pt-BR')
                                : 'ainda não sincronizou'
                        }}
                    </span>
                    <Form
                        v-bind="BinanceController.sync.form()"
                        v-slot="{ processing }"
                        class="inline"
                    >
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            :disabled="processing"
                        >
                            Sincronizar agora
                        </Button>
                    </Form>
                </div>
                <p v-if="account_sync.dust.length > 0">
                    Poeira (abaixo de 1 USDT),
                    {{ account_sync.dust.length }} ativo(s), total
                    {{ account_sync.dust_total.toFixed(2) }} USDT:
                    {{
                        account_sync.dust
                            .map((item) => `${item.coin} ${item.qty}`)
                            .join(', ')
                    }}.
                </p>
                <ul
                    v-if="account_sync.not_mirrored.length > 0"
                    class="list-disc pl-5"
                >
                    <li
                        v-for="item in account_sync.not_mirrored"
                        :key="item.coin"
                    >
                        {{ item.coin }} ({{ item.qty }}):
                        {{ item.status === 'sem_cotacao' ? 'sem cotação. ' : ''
                        }}{{ item.reason }}
                        <template v-if="item.value !== null">
                            Valor estimado {{ item.value.toFixed(2) }} USDT.
                        </template>
                    </li>
                </ul>
                <p class="text-xs">
                    Só a carteira Spot é lida. Saldos em Earn, Funding, Margin
                    ou Futuros da Binance não aparecem aqui.
                </p>
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
