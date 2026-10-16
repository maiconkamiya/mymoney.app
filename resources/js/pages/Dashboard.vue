<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, ShieldCheck, ShieldOff } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FeedStatus from '@/components/landing/FeedStatus.vue';
import PriceChart from '@/components/landing/PriceChart.vue';
import { Badge } from '@/components/ui/badge';
import {
    ASSETS,
    assets,
    brl,
    candles,
    changePct,
    pct,
    startMarket,
    stopMarket,
    type AssetId,
} from '@/lib/mercado';
import { actionLabels, marketLabels, money, statusLabels } from '@/lib/trading';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/portfolios';
import type { PendingSignal, PortfolioSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Painel', href: dashboard() }],
    },
});

const props = defineProps<{
    halted: boolean;
    portfolios: PortfolioSummary[];
    pending_signals: PendingSignal[];
}>();

// Cripto é cotada em USDT e a B3 em real, então o patrimônio é somado por mercado e o resultado é a média dos retornos.
const totals = computed(() => {
    const byMarket = (['b3', 'crypto'] as const)
        .map((market) => ({
            market,
            equity: props.portfolios
                .filter((p) => p.market === market)
                .reduce((s, p) => s + p.equity, 0),
            count: props.portfolios.filter((p) => p.market === market).length,
        }))
        .filter((m) => m.count > 0);
    const returns = props.portfolios
        .filter((p) => p.initial_cash > 0)
        .map((p) => (p.equity / p.initial_cash - 1) * 100);

    return {
        byMarket,
        resultPct: returns.length
            ? returns.reduce((s, r) => s + r, 0) / returns.length
            : 0,
    };
});

const equityLabel = (market: string, value: number) =>
    market === 'b3'
        ? money(value, 'b3')
        : `${money(value, 'crypto').replace(/(,\d{2})\d*$/, '$1')} USDT`;

const selected = ref<AssetId>('btc');
const series = computed(() => candles[selected.value].value);
const change = computed(() => changePct(selected.value));

onMounted(startMarket);
onBeforeUnmount(stopMarket);
</script>

<template>
    <Head title="Painel" />

    <div
        class="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8"
    >
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight md:text-3xl">
                    Painel
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Suas simulações, os sinais que esperam você e o mercado
                    agora.
                </p>
            </div>
            <Link
                :href="index()"
                class="press inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium whitespace-nowrap"
                :class="
                    halted
                        ? 'border-destructive/50 bg-destructive/10 text-destructive'
                        : 'bg-card'
                "
            >
                <ShieldOff v-if="halted" class="size-4" />
                <ShieldCheck v-else class="size-4 text-up" />
                {{ halted ? 'Parada geral acionada' : 'Negociação liberada' }}
            </Link>
        </div>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-6 lg:grid-cols-4">
            <div>
                <dt class="text-sm text-muted-foreground">Patrimônio</dt>
                <dd
                    v-if="totals.byMarket.length === 0"
                    class="num mt-1 text-2xl font-semibold"
                >
                    {{ money(0, 'b3') }}
                </dd>
                <dd
                    v-for="m in totals.byMarket"
                    :key="m.market"
                    class="num mt-1 font-semibold"
                    :class="totals.byMarket.length > 1 ? 'text-lg' : 'text-2xl'"
                >
                    {{ equityLabel(m.market, m.equity) }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Resultado médio</dt>
                <dd
                    class="num mt-1 text-2xl font-semibold"
                    :class="
                        totals.resultPct > 0
                            ? 'text-up'
                            : totals.resultPct < 0
                              ? 'text-down'
                              : ''
                    "
                >
                    {{ pct(totals.resultPct) }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    Carteiras em execução
                </dt>
                <dd class="num mt-1 text-2xl font-semibold">
                    {{ portfolios.length }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Sinais aguardando</dt>
                <dd class="num mt-1 text-2xl font-semibold">
                    {{ pending_signals.length }}
                </dd>
            </div>
        </dl>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <section class="rounded-2xl border bg-card p-4 md:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="font-medium">Mercado</h2>
                        <FeedStatus compact class="mt-1" />
                    </div>
                    <div
                        class="flex rounded-full border p-1"
                        role="tablist"
                        aria-label="Ativo do gráfico"
                    >
                        <button
                            v-for="asset in ASSETS"
                            :key="asset.id"
                            type="button"
                            role="tab"
                            :aria-selected="selected === asset.id"
                            class="press rounded-full px-3 py-1 text-sm whitespace-nowrap"
                            :class="
                                selected === asset.id
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground'
                            "
                            @click="selected = asset.id"
                        >
                            {{ asset.pair }}
                        </button>
                    </div>
                </div>
                <p class="mt-4 flex items-baseline gap-3">
                    <span class="num text-3xl font-semibold">{{
                        brl(assets[selected].price)
                    }}</span>
                    <span
                        class="num text-sm"
                        :class="change >= 0 ? 'text-up' : 'text-down'"
                        >{{ pct(change) }} em 3 h</span
                    >
                </p>
                <div class="mt-4">
                    <PriceChart :data="series" :up="change >= 0" />
                </div>
            </section>

            <section class="flex flex-col rounded-2xl border bg-card">
                <div class="p-4 md:p-6 md:pb-4">
                    <h2 class="font-medium">Aguardando sua decisão</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Sinais aprovados pelos agentes que só viram ordem com o
                        seu aval.
                    </p>
                </div>
                <p
                    v-if="pending_signals.length === 0"
                    class="mx-4 mb-4 rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:mx-6 md:mb-6"
                >
                    Nenhum sinal esperando agora.
                </p>
                <ul v-else class="px-2 pb-2">
                    <li
                        v-for="signal in pending_signals"
                        :key="signal.id"
                        class="rounded-xl even:bg-muted/40"
                    >
                        <Link
                            :href="show(signal.portfolio_id)"
                            class="flex items-center justify-between gap-3 px-3 py-2.5 text-sm"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <Badge
                                    variant="secondary"
                                    :class="
                                        signal.side === 'buy'
                                            ? 'text-up'
                                            : signal.side === 'sell'
                                              ? 'text-down'
                                              : ''
                                    "
                                >
                                    {{
                                        signal.side
                                            ? actionLabels[signal.side]
                                            : 'Aguardar'
                                    }}
                                </Badge>
                                <span class="font-medium">{{
                                    signal.symbol
                                }}</span>
                                <span class="truncate text-muted-foreground">{{
                                    signal.portfolio
                                }}</span>
                            </span>
                            <ArrowUpRight
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <section>
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 class="font-medium">Carteiras em execução</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Patrimônio atual de cada simulação em andamento.
                    </p>
                </div>
                <Link
                    :href="index()"
                    class="text-sm whitespace-nowrap text-primary underline-offset-4 hover:underline"
                >
                    Ver todas
                </Link>
            </div>

            <div
                v-if="portfolios.length === 0"
                class="mt-4 rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nenhuma carteira em execução.
                <Link :href="index()" class="text-primary underline"
                    >Criar uma</Link
                >
            </div>

            <div v-else class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="portfolio in portfolios"
                    :key="portfolio.id"
                    :href="show(portfolio.id)"
                    class="press rounded-2xl border bg-card p-5 transition-colors hover:border-primary/40"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate font-medium">{{ portfolio.name }}</p>
                        <Badge variant="outline">{{
                            statusLabels[portfolio.status] ?? portfolio.status
                        }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ marketLabels[portfolio.market] }}, de
                        {{ portfolio.starts_at }} a {{ portfolio.ends_at }}
                    </p>
                    <p class="num mt-4 text-xl font-semibold">
                        {{ equityLabel(portfolio.market, portfolio.equity) }}
                    </p>
                    <p
                        class="num text-sm"
                        :class="
                            portfolio.equity >= portfolio.initial_cash
                                ? 'text-up'
                                : 'text-down'
                        "
                    >
                        {{
                            pct(
                                portfolio.initial_cash > 0
                                    ? (portfolio.equity /
                                          portfolio.initial_cash -
                                          1) *
                                          100
                                    : 0,
                            )
                        }}
                    </p>
                </Link>
            </div>
        </section>
    </div>
</template>
