<script setup lang="ts">
import { echoIsConfigured, useEcho } from '@laravel/echo-vue';
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount } from 'vue';
import { useMarketPrices } from '@/composables/useMarketPrices';
import {
    realtimeProps,
    usePortfolioRealtime,
} from '@/composables/usePortfolioRealtime';
import RealtimeIndicator from '@/components/portfolio/RealtimeIndicator.vue';
import BinanceController from '@/actions/App/Http/Controllers/Settings/BinanceController';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import EditInitialCash from '@/components/portfolio/EditInitialCash.vue';
import BinanceTotal from '@/components/portfolio/BinanceTotal.vue';
import AccountLeftovers from '@/components/portfolio/AccountLeftovers.vue';
import DeletePortfolio from '@/components/portfolio/DeletePortfolio.vue';
import ClonePortfolio from '@/components/portfolio/ClonePortfolio.vue';
import AssetChartsCard from '@/components/portfolio/AssetChartsCard.vue';
import AgentSwarmCard from '@/components/portfolio/AgentSwarmCard.vue';
import TokenUsageCard from '@/components/portfolio/TokenUsageCard.vue';
import TrackedAssetsCard from '@/components/portfolio/TrackedAssetsCard.vue';
import ExplorerFollowCard from '@/components/portfolio/ExplorerFollowCard.vue';
import BrokerOrdersList from '@/components/portfolio/BrokerOrdersList.vue';
import LiveExecutionCard from '@/components/portfolio/LiveExecutionCard.vue';
import MicroOperationsCard from '@/components/portfolio/MicroOperationsCard.vue';
import SignalCard from '@/components/portfolio/SignalCard.vue';
import PerformancePanel from '@/components/portfolio/PerformancePanel.vue';
import SidebarSection from '@/components/portfolio/SidebarSection.vue';
import { useOpenSection } from '@/composables/useOpenSection';
import { Button } from '@/components/ui/button';
import { marketLabels, money, percent } from '@/lib/trading';
import { avisoFimNegociacao, dataCurta } from '@/lib/fimNegociacao';
import { index, update } from '@/routes/portfolios';
import { signals as portfolioSignals } from '@/routes/portfolios';
import { edit as systemSettings } from '@/routes/system-settings';
import type {
    AccountSync,
    AgentActivity,
    AgentScoreRow,
    AvailableAsset,
    ExplorerFollow,
    LiveInfo,
    MicroOverview,
    Performance,
    PortfolioCharts,
    PortfolioSummary,
    PositionRow,
    SignalRow,
    TaxMonth,
    TokenUsage,
} from '@/types';

const props = defineProps<{
    portfolio: PortfolioSummary;
    positions: PositionRow[];
    account_sync: AccountSync | null;
    signals: SignalRow[];
    signals_total: number;
    performance: Performance;
    agent_scores: AgentScoreRow[];
    tax_months: TaxMonth[];
    agents_enabled: boolean;
    agent_activity: AgentActivity;
    token_usage: TokenUsage;
    charts: PortfolioCharts;
    available_assets: AvailableAsset[];
    tracked_asset_ids: number[];
    explorer_follow: ExplorerFollow | null;
    live: LiveInfo | null;
    micro: MicroOverview | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carteiras', href: index() }],
    },
});

// Saldo, posições, ordens, sinais e micro operações chegam pelo canal privado da
// carteira; o preço de agora das posições, pelo canal público `mercado`.
const realtime = usePortfolioRealtime(props.portfolio.id);
const marketPrices = useMarketPrices();

const livePositions = computed(() =>
    props.positions.map((position) => {
        const price =
            marketPrices[position.symbol] ?? position.last_price ?? null;

        return {
            ...position,
            price,
            value: price === null ? position.value : position.qty * price,
            pnl: price === null ? null : price / position.avg_price - 1,
        };
    }),
);

// Mesma conta do servidor (caixa + posições a preço de agora, ou preço médio sem cotação).
const equity = computed(
    () =>
        (props.portfolio.cash ?? 0) +
        livePositions.value.reduce(
            (sum, position) =>
                sum + position.qty * (position.price ?? position.avg_price),
            0,
        ),
);

const result = computed(() => equity.value / props.portfolio.initial_cash - 1);

// Agentes desligados em Custos e serviços: os cards do enxame somem. Sinais só
// fica enquanto algum aprovado espera confirmação ou execução.
const showSignals = computed(
    () =>
        props.agents_enabled ||
        props.signals.some((signal) =>
            ['approved', 'confirmed'].includes(signal.stage),
        ),
);

// Sidebar: uma seção aberta por vez, lembrada por carteira neste navegador.
const section = useOpenSection(
    `portfolio.${props.portfolio.id}.sidebar`,
    'positions',
);

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
        },
    );
}

onBeforeUnmount(() => clearTimeout(reloadTimer));
</script>

<template>
    <Head :title="portfolio.name" />

    <div class="flex w-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="portfolio.name"
                :description="`${marketLabels[portfolio.market]} · ${portfolio.mode === 'paper' ? 'simulada' : 'real'} · ${portfolio.starts_at} a ${portfolio.ends_at} · ${portfolio.auto_execute ? 'execução automática' : 'aprovação manual'}`"
            />
            <RealtimeIndicator
                :status="realtime.status.value"
                :last-update="realtime.lastUpdate.value"
            />
        </div>

        <div
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(360px,440px)]"
        >
            <div class="flex min-w-0 flex-col gap-8">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-sm text-muted-foreground">Patrimônio</p>
                        <p
                            class="text-2xl font-semibold tabular-nums"
                            data-test="portfolio-equity"
                        >
                            {{ money(equity, portfolio.market) }}
                        </p>
                        <BinanceTotal
                            v-if="account_sync"
                            :equity="equity"
                            :sync="account_sync"
                        />
                    </div>
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-sm text-muted-foreground">Caixa</p>
                        <p
                            class="text-2xl font-semibold tabular-nums"
                            data-test="portfolio-cash"
                        >
                            {{ money(portfolio.cash, portfolio.market) }}
                        </p>
                    </div>
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-sm text-muted-foreground">
                            Resultado sobre
                            {{
                                money(
                                    Math.round(portfolio.initial_cash * 100) /
                                        100,
                                    portfolio.market,
                                )
                            }}
                        </p>
                        <div class="mt-2">
                            <EditInitialCash :portfolio="portfolio" />
                        </div>
                        <p
                            class="text-2xl font-semibold tabular-nums"
                            :class="result < 0 ? 'text-down' : 'text-up'"
                        >
                            {{ percent(result) }}
                        </p>
                    </div>
                </div>

                <AssetChartsCard
                    :portfolio-id="portfolio.id"
                    :market="portfolio.market"
                    :initial="charts"
                    :tracked="tracked_asset_ids"
                />

                <!-- O card usa container queries (@sm, @xl, @3xl), como na sidebar. -->
                <div v-if="micro" class="@container">
                    <MicroOperationsCard
                        :portfolio="portfolio"
                        :micro="micro"
                    />
                </div>

                <template v-if="agents_enabled">
                    <AgentSwarmCard
                        :portfolio-id="portfolio.id"
                        :initial="agent_activity"
                    />

                    <TokenUsageCard :usage="token_usage" />
                </template>
                <p
                    v-else
                    class="-mt-4 text-xs text-muted-foreground"
                    data-test="agents-disabled"
                >
                    Agentes em ação desligados em
                    <Link
                        :href="systemSettings()"
                        class="underline underline-offset-4 hover:text-foreground"
                        >Custos e serviços</Link
                    >. Posições já abertas seguem com stop e alvo.
                </p>

                <PerformancePanel
                    :portfolio-id="portfolio.id"
                    :market="portfolio.market"
                    :performance="performance"
                    :agent-scores="agent_scores"
                    :show-agent-scores="agents_enabled"
                    :tax-months="tax_months"
                />

                <section v-if="showSignals" data-test="signals-section">
                    <Heading
                        variant="small"
                        title="Sinais"
                        description="O sinal mais recente de cada ativo. Abra o card para ver os votos e o cálculo do score. Sinais aprovados esperam sua confirmação."
                    />

                    <p
                        v-if="signals.length === 0"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        Nenhum sinal ainda. Eles aparecem quando o scanner ou
                        uma notícia relevante dispara uma rodada de agentes.
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

            <aside
                class="flex flex-col gap-3 xl:sticky xl:top-4 xl:max-h-[calc(100svh-2rem)] xl:overflow-y-auto xl:pr-1"
                data-test="portfolio-sidebar"
            >
                <SidebarSection
                    v-if="portfolio.mode === 'live' && live"
                    title="Execução com dinheiro real"
                    :hint="
                        portfolio.orders_blocked
                            ? 'Novas ordens bloqueadas'
                            : 'Limites, parar tudo e execução automática'
                    "
                    :tone="portfolio.orders_blocked ? 'danger' : 'default'"
                    :open="section.open.value === 'live'"
                    @toggle="section.toggle('live')"
                >
                    <LiveExecutionCard :portfolio="portfolio" :live="live" />
                </SidebarSection>

                <SidebarSection
                    title="Posições abertas"
                    :hint="
                        positions.length === 0
                            ? 'Nenhuma posição'
                            : `${positions.length} posição(ões)`
                    "
                    :open="section.open.value === 'positions'"
                    @toggle="section.toggle('positions')"
                >
                    <div>
                        <p
                            v-if="positions.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Nenhuma posição aberta.
                        </p>
                        <div v-else class="overflow-x-auto rounded-xl border">
                            <table class="w-full text-sm">
                                <thead
                                    class="bg-muted/50 text-left text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-4 py-2 font-medium">
                                            Ativo
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Quantidade
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Preço médio
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Preço agora
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Valor (USDT)
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Resultado
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Stop
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="position in livePositions"
                                        :key="position.id"
                                        class="border-t"
                                        :data-test="`position-${position.symbol}`"
                                    >
                                        <td class="px-4 py-2 font-medium">
                                            {{ position.symbol }}
                                            <span
                                                v-if="position.trading_ends_on"
                                                class="ml-1 text-xs font-normal whitespace-nowrap text-amber-400"
                                            >
                                                {{
                                                    position.trading_ended
                                                        ? 'Encerrada'
                                                        : `Até ${dataCurta(position.trading_ends_on).slice(0, 5)}`
                                                }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{ position.qty }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{
                                                money(
                                                    position.avg_price,
                                                    portfolio.market,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                            data-test="position-price"
                                        >
                                            {{
                                                position.price === null
                                                    ? '-'
                                                    : position.price.toLocaleString(
                                                          'pt-BR',
                                                          {
                                                              maximumSignificantDigits: 6,
                                                          },
                                                      )
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{
                                                position.value === null
                                                    ? '-'
                                                    : money(
                                                          position.value,
                                                          portfolio.market,
                                                      )
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                            :class="
                                                position.pnl === null
                                                    ? ''
                                                    : position.pnl < 0
                                                      ? 'text-down'
                                                      : 'text-up'
                                            "
                                        >
                                            {{
                                                position.pnl === null
                                                    ? '-'
                                                    : percent(position.pnl)
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right tabular-nums"
                                        >
                                            {{
                                                money(
                                                    position.stop_price,
                                                    portfolio.market,
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <ul
                            v-if="
                                positions.some(
                                    (position) => position.trading_ends_on,
                                )
                            "
                            class="mt-3 space-y-2 text-xs"
                        >
                            <li
                                v-for="position in positions.filter(
                                    (row) => row.trading_ends_on,
                                )"
                                :key="position.id"
                                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-2"
                                :data-test="`position-${position.symbol}-trading-end`"
                            >
                                <p
                                    class="text-amber-400"
                                    data-test="position-trading-end"
                                >
                                    <span class="font-medium">{{
                                        position.symbol
                                    }}</span>
                                    ·
                                    {{
                                        avisoFimNegociacao(
                                            position.trading_ends_on,
                                            position.trading_ended,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="position.delisting_block"
                                    class="mt-1 text-destructive"
                                    data-test="position-delisting-block"
                                >
                                    A venda não saiu:
                                    {{ position.delisting_block }}
                                </p>
                            </li>
                        </ul>
                        <ul
                            v-if="
                                positions.some(
                                    (position) => position.below_minimum,
                                )
                            "
                            class="mt-3 space-y-2 text-xs"
                        >
                            <li
                                v-for="position in positions.filter(
                                    (row) => row.below_minimum,
                                )"
                                :key="position.id"
                                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-2 text-amber-400"
                                :data-test="`position-${position.symbol}-below-minimum`"
                            >
                                <span class="font-medium">{{
                                    position.symbol
                                }}</span>
                                · {{ position.below_minimum }}
                            </li>
                        </ul>

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
                                    v-slot="{ errors, processing }"
                                    :options="{
                                        preserveScroll: true,
                                        only: realtimeProps,
                                    }"
                                    class="inline-flex items-center gap-2"
                                >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        :disabled="processing"
                                    >
                                        {{
                                            processing
                                                ? 'Sincronizando...'
                                                : 'Sincronizar agora'
                                        }}
                                    </Button>
                                    <InputError :message="errors.sync" />
                                </Form>
                            </div>
                            <AccountLeftovers :sync="account_sync" />
                            <BrokerOrdersList
                                :orders="account_sync.orders"
                                :errors="account_sync.order_errors"
                            />
                            <p class="text-xs">
                                O app opera só a carteira Spot. Saldos em Earn,
                                Funding, Margin ou Futuros entram apenas no
                                total da conta Binance, abaixo do patrimônio.
                            </p>
                        </div>
                    </div>
                </SidebarSection>

                <SidebarSection
                    title="Ativos acompanhados"
                    :hint="
                        explorer_follow?.enabled
                            ? `${tracked_asset_ids.length} ativo(s), seguindo o explorador`
                            : `${tracked_asset_ids.length} ativo(s)`
                    "
                    :open="section.open.value === 'tracked'"
                    @toggle="section.toggle('tracked')"
                >
                    <div class="space-y-3">
                        <ExplorerFollowCard
                            v-if="explorer_follow"
                            :portfolio-id="portfolio.id"
                            :follow="explorer_follow"
                            :tracked-count="tracked_asset_ids.length"
                            :agents-enabled="agents_enabled"
                        />
                        <TrackedAssetsCard
                            :portfolio-id="portfolio.id"
                            :available="available_assets"
                            :tracked="tracked_asset_ids"
                            :locked="explorer_follow?.enabled ?? false"
                        />
                    </div>
                </SidebarSection>

                <SidebarSection
                    v-if="portfolio.mode !== 'live' && agents_enabled"
                    title="Execução dos sinais"
                    :hint="
                        portfolio.auto_execute
                            ? 'Automática'
                            : 'Com sua confirmação'
                    "
                    :open="section.open.value === 'auto'"
                    @toggle="section.toggle('auto')"
                >
                    <section class="rounded-2xl border bg-card p-4">
                        <Form
                            v-bind="update.form(portfolio.id)"
                            v-slot="{ errors, processing }"
                            :options="{
                                preserveScroll: true,
                                only: ['portfolio'],
                            }"
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="auto_execute"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="auto_execute"
                                    value="1"
                                    class="mt-0.5"
                                    :checked="portfolio.auto_execute"
                                />
                                <span>
                                    Executar sinais aprovados sem confirmação
                                    manual
                                    <span class="block text-muted-foreground">
                                        Desligado, cada sinal aprovado pelos
                                        agentes espera a sua confirmação. A
                                        guarda de risco vale nos dois casos.
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
                </SidebarSection>

                <SidebarSection
                    title="Clonar como simulada"
                    hint="Testar sem dinheiro"
                    :open="section.open.value === 'clone'"
                    @toggle="section.toggle('clone')"
                >
                    <ClonePortfolio :portfolio="portfolio" :equity="equity" />
                </SidebarSection>

                <SidebarSection
                    title="Excluir carteira"
                    tone="danger"
                    :open="section.open.value === 'delete'"
                    @toggle="section.toggle('delete')"
                >
                    <section
                        class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border p-4"
                    >
                        <div>
                            <p class="font-medium">Excluir carteira</p>
                            <p class="text-sm text-muted-foreground">
                                Apaga a carteira e tudo que está ligado a ela.
                                Não pode ser desfeito.
                            </p>
                        </div>
                        <DeletePortfolio :portfolio="portfolio" />
                    </section>
                </SidebarSection>
            </aside>
        </div>
    </div>
</template>
