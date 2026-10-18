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
import DeletePortfolio from '@/components/portfolio/DeletePortfolio.vue';
import AssetChartsCard from '@/components/portfolio/AssetChartsCard.vue';
import AgentSwarmCard from '@/components/portfolio/AgentSwarmCard.vue';
import TrackedAssetsCard from '@/components/portfolio/TrackedAssetsCard.vue';
import LiveExecutionCard from '@/components/portfolio/LiveExecutionCard.vue';
import MicroOperationsCard from '@/components/portfolio/MicroOperationsCard.vue';
import SignalCard from '@/components/portfolio/SignalCard.vue';
import PerformancePanel from '@/components/portfolio/PerformancePanel.vue';
import { Button } from '@/components/ui/button';
import { marketLabels, money, percent } from '@/lib/trading';
import { index, update } from '@/routes/portfolios';
import { signals as portfolioSignals } from '@/routes/portfolios';
import type {
    AccountSync,
    AgentActivity,
    AgentScoreRow,
    AvailableAsset,
    LiveInfo,
    MicroOverview,
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
    signals_total: number;
    performance: Performance;
    agent_scores: AgentScoreRow[];
    tax_months: TaxMonth[];
    agent_activity: AgentActivity;
    charts: PortfolioCharts;
    available_assets: AvailableAsset[];
    tracked_asset_ids: number[];
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

    <div
        class="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8"
    >
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

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Patrimônio</p>
                <p
                    class="text-2xl font-semibold tabular-nums"
                    data-test="portfolio-equity"
                >
                    {{ money(equity, portfolio.market) }}
                </p>
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
                    {{ money(portfolio.initial_cash, portfolio.market) }}
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

        <section
            v-if="portfolio.mode !== 'live'"
            class="rounded-2xl border bg-card p-4"
        >
            <Form
                v-bind="update.form(portfolio.id)"
                v-slot="{ errors, processing }"
                :options="{ preserveScroll: true, only: ['portfolio'] }"
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

        <MicroOperationsCard
            v-if="micro"
            :portfolio="portfolio"
            :micro="micro"
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
                                Preço agora
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Valor (USDT)
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Resultado
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
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
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ position.qty }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{
                                    money(position.avg_price, portfolio.market)
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
                                              { maximumSignificantDigits: 6 },
                                          )
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
                        v-slot="{ errors, processing }"
                        :options="{ preserveScroll: true, only: realtimeProps }"
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

        <section
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border p-4"
        >
            <div>
                <p class="font-medium">Excluir carteira</p>
                <p class="text-sm text-muted-foreground">
                    Apaga a carteira e tudo que está ligado a ela. Não pode ser
                    desfeito.
                </p>
            </div>
            <DeletePortfolio :portfolio="portfolio" />
        </section>
    </div>
</template>
