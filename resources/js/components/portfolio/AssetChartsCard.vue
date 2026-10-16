<script setup lang="ts">
import { echoIsConfigured, useEchoPublic } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PortfolioChartsController from '@/actions/App/Http/Controllers/PortfolioChartsController';
import AssetChart from '@/components/portfolio/AssetChart.vue';
import Heading from '@/components/Heading.vue';
import {
    applyKline,
    klineFromServer,
    klineStreamUrl,
    windowChange,
} from '@/lib/liveCharts';
import type { Kline, ServerCandle } from '@/lib/liveCharts';
import { money, percent } from '@/lib/trading';
import type { ChartAsset, ChartCandle, PortfolioCharts } from '@/types';

const props = defineProps<{
    portfolioId: number;
    market: string;
    initial: PortfolioCharts;
}>();

/** Rede de segurança: sem o WebSocket (ou na B3), uma foto do servidor a cada tanto. */
const POLL_MS = 15000;
const POLL_B3_MS = 30000;
const STREAM_HOST =
    (import.meta.env.VITE_BINANCE_STREAM_HOST as string | undefined) ||
    'data-stream.binance.vision';

const charts = ref<PortfolioCharts>(props.initial);
const series = ref<Record<string, ChartCandle[]>>(
    Object.fromEntries(props.initial.assets.map((a) => [a.symbol, a.series])),
);
const flash = ref<Record<string, 'up' | 'down' | null>>({});
const socketUp = ref(false);
const serverLive = ref(false);
const failed = ref(false);
const isCrypto = props.market === 'crypto';

let socket: WebSocket | null = null;
let clock: ReturnType<typeof setInterval> | null = null;
let timer: ReturnType<typeof setInterval> | null = null;
let retry: ReturnType<typeof setTimeout> | null = null;
let inFlight = false;
let lastServerEvent = 0;
let stopped = false;

const cards = computed(() =>
    charts.value.assets.map((asset: ChartAsset) => {
        const data = series.value[asset.symbol] ?? [];
        const last = data[data.length - 1];
        const change = windowChange(data);
        const price = last ? last[4] : null;
        const fmt = (v: number) =>
            asset.quote_currency === 'USDT'
                ? new Intl.NumberFormat('pt-BR', {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: v < 1 ? 6 : 2,
                  }).format(v)
                : money(v, 'b3');
        const pnl =
            asset.position && price !== null
                ? (price / asset.position.avg_price - 1) * 100
                : null;

        return { asset, data, price, change, fmt, pnl };
    }),
);

const status = computed(() => {
    if (isCrypto) {
        return socketUp.value
            ? { text: 'Ao vivo', live: true }
            : serverLive.value
              ? { text: 'Ao vivo pelo servidor', live: true }
              : {
                    text: failed.value
                        ? 'Sem atualização, tentando de novo.'
                        : 'Tempo real fora do ar, atualizando a cada 15 s.',
                    live: false,
                };
    }

    return charts.value.market_open
        ? { text: 'Pregão aberto, atualizando a cada 30 s.', live: false }
        : { text: 'Pregão fechado, mostrando a última cotação.', live: false };
});

async function refresh() {
    if (
        inFlight ||
        document.hidden ||
        (isCrypto && socketUp.value) ||
        Date.now() - lastServerEvent < POLL_MS
    ) {
        return;
    }

    inFlight = true;

    try {
        const response = await fetch(
            PortfolioChartsController.url(props.portfolioId),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const fresh = (await response.json()) as PortfolioCharts;
        charts.value = fresh;
        series.value = Object.fromEntries(
            fresh.assets.map((a) => [a.symbol, a.series]),
        );
        failed.value = false;
    } catch {
        failed.value = true;
    } finally {
        inFlight = false;
    }
}

function applyCandle(symbol: string, kline: Kline) {
    const before = series.value[symbol];

    if (!before) {
        return;
    }

    const next = applyKline(before, kline);
    const was = before[before.length - 1]?.[4];
    const now = next[next.length - 1]?.[4];

    series.value = { ...series.value, [symbol]: next };

    if (was !== undefined && now !== undefined && now !== was) {
        flash.value = { ...flash.value, [symbol]: now > was ? 'up' : 'down' };
        setTimeout(() => {
            flash.value = { ...flash.value, [symbol]: null };
        }, 600);
    }
}

/** Com o navegador sem acesso à Binance, o coletor do servidor assume pelo canal `mercado`. */
if (isCrypto && echoIsConfigured()) {
    useEchoPublic<{ symbol: string; price: number; candle: ServerCandle }>(
        'mercado',
        '.cotacao.atualizada',
        (event) => {
            if (socketUp.value) {
                return;
            }

            lastServerEvent = Date.now();
            serverLive.value = true;
            applyCandle(event.symbol, klineFromServer(event.candle));
        },
    );
}

function connect() {
    if (stopped || !isCrypto || charts.value.assets.length === 0) {
        return;
    }

    try {
        socket = new WebSocket(
            klineStreamUrl(
                charts.value.assets.map((a) => a.symbol),
                STREAM_HOST,
            ),
        );
    } catch {
        return;
    }

    socket.onopen = () => {
        socketUp.value = true;
    };
    socket.onmessage = (event) => {
        const message = JSON.parse(event.data as string) as {
            data?: { s: string; k: Kline };
        };

        if (!message.data?.k) {
            return;
        }

        applyCandle(message.data.s, message.data.k);
    };
    socket.onclose = () => {
        socketUp.value = false;
        socket = null;

        if (!stopped) {
            retry = setTimeout(connect, 5000);
        }
    };
    socket.onerror = () => socket?.close();
}

onMounted(() => {
    connect();
    timer = setInterval(refresh, isCrypto ? POLL_MS : POLL_B3_MS);
    clock = setInterval(() => {
        serverLive.value = Date.now() - lastServerEvent < POLL_MS;
    }, 5000);
    document.addEventListener('visibilitychange', refresh);
});

onBeforeUnmount(() => {
    stopped = true;
    socket?.close();

    if (timer) {
        clearInterval(timer);
    }

    if (clock) {
        clearInterval(clock);
    }

    if (retry) {
        clearTimeout(retry);
    }

    document.removeEventListener('visibilitychange', refresh);
});
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <Heading
                variant="small"
                title="Ativos em tempo real"
                description="Preço por minuto dos ativos que a carteira opera. Linhas tracejadas marcam o preço médio e o stop das posições abertas."
            />
            <p
                class="flex items-center gap-2 text-xs text-muted-foreground"
                data-testid="charts-status"
            >
                <span
                    class="size-1.5 rounded-full"
                    :class="status.live ? 'bg-up' : 'bg-muted-foreground/50'"
                />
                {{ status.text }}
            </p>
        </div>

        <p v-if="cards.length === 0" class="text-sm text-muted-foreground">
            Nenhum ativo ativo neste mercado ainda.
        </p>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="card in cards"
                :key="card.asset.symbol"
                class="rounded-2xl border bg-card p-4"
                data-testid="asset-chart"
            >
                <header class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ card.asset.symbol }}</p>
                        <p
                            v-if="card.asset.position"
                            class="text-xs text-muted-foreground"
                        >
                            Em posição
                            <span
                                v-if="card.pnl !== null"
                                class="tabular-nums"
                                :class="card.pnl < 0 ? 'text-down' : 'text-up'"
                            >
                                · {{ percent(card.pnl / 100) }}
                            </span>
                        </p>
                        <p
                            v-else-if="card.asset.name"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ card.asset.name }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p
                            class="font-mono text-lg font-semibold tabular-nums transition-colors duration-500"
                            :class="
                                flash[card.asset.symbol] === 'up'
                                    ? 'text-up'
                                    : flash[card.asset.symbol] === 'down'
                                      ? 'text-down'
                                      : ''
                            "
                        >
                            {{
                                card.price !== null ? card.fmt(card.price) : '-'
                            }}
                        </p>
                        <p
                            class="font-mono text-xs tabular-nums"
                            :class="card.change < 0 ? 'text-down' : 'text-up'"
                        >
                            {{ percent(card.change / 100) }}
                            <span class="text-muted-foreground">na janela</span>
                        </p>
                    </div>
                </header>
                <div class="mt-3">
                    <AssetChart
                        :data="card.data"
                        :up="card.change >= 0"
                        :avg-price="card.asset.position?.avg_price"
                        :stop-price="card.asset.position?.stop_price"
                        :format="card.fmt"
                    />
                </div>
            </article>
        </div>
    </section>
</template>
