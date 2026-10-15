<script setup lang="ts">
import { echo } from '@laravel/echo-vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import CandleChart from '@/components/CandleChart.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { mergeCandle, priceFormat } from '@/lib/market';
import { dashboard } from '@/routes';
import type {
    CandleUpdatedEvent,
    Candle,
    CollectorSnapshot,
    MarketAsset,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    assets: MarketAsset[];
    collector: CollectorSnapshot;
}>();

const series = ref<Record<string, Candle[]>>(
    Object.fromEntries(
        props.assets.map((asset) => [asset.symbol, asset.candles]),
    ),
);
const lastTickAt = ref<Record<string, number>>(
    Object.fromEntries(
        Object.entries(props.collector.symbols).map(([symbol, tick]) => [
            symbol,
            tick.at,
        ]),
    ),
);
const connected = ref(props.collector.connected);
const selected = ref(props.assets[0]?.symbol ?? '');
const now = ref(Date.now());

const current = computed(() =>
    props.assets.find((asset) => asset.symbol === selected.value),
);
const candles = computed(() => series.value[selected.value] ?? []);
const lastPrice = computed(() => candles.value.at(-1)?.close ?? null);
const change = computed(() => {
    const first = candles.value.at(0)?.open;

    return first && lastPrice.value !== null
        ? ((lastPrice.value - first) / first) * 100
        : null;
});

const newestTick = computed(() => {
    const times = Object.values(lastTickAt.value);

    return times.length > 0 ? Math.max(...times) : null;
});
const lag = computed(() =>
    newestTick.value === null
        ? null
        : Math.max(0, Math.round(now.value / 1000 - newestTick.value)),
);
const staleAfter = 15;
const state = computed<'live' | 'stalled' | 'offline'>(() => {
    if (!connected.value) {
        return 'offline';
    }

    return lag.value === null || lag.value > staleAfter ? 'stalled' : 'live';
});
const stateLabel = {
    live: 'Ao vivo',
    stalled: 'Atrasado',
    offline: 'Coletor parado',
} as const;

function tickerFor(symbol: string): number | null {
    return series.value[symbol]?.at(-1)?.close ?? null;
}

let timer: ReturnType<typeof setInterval> | undefined;
const channels: string[] = [];

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 1000);

    const socket = echo();

    for (const asset of props.assets) {
        const name = `market.${asset.symbol}`;
        channels.push(name);

        socket
            .private(name)
            .listen('.candle.updated', (event: CandleUpdatedEvent) => {
                series.value = {
                    ...series.value,
                    [event.symbol]: mergeCandle(
                        series.value[event.symbol] ?? [],
                        event.candle,
                    ),
                };
                lastTickAt.value = {
                    ...lastTickAt.value,
                    [event.symbol]: event.event_at,
                };
                connected.value = true;
            });
    }

    channels.push('market.status');
    socket
        .private('market.status')
        .listen('.collector.status', (event: CollectorSnapshot) => {
            connected.value = event.connected;
        });
});

onBeforeUnmount(() => {
    clearInterval(timer);

    const socket = echo();

    for (const name of channels) {
        socket.leave(name);
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div
            v-if="assets.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Nenhum ativo cripto ativo. Rode
            <code>php artisan db:seed --class=MarketSeeder</code>.
        </div>

        <template v-else>
            <div
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-sidebar-border/70 p-4"
            >
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="asset in assets"
                        :key="asset.symbol"
                        :variant="
                            asset.symbol === selected ? 'default' : 'outline'
                        "
                        size="sm"
                        @click="selected = asset.symbol"
                    >
                        {{ asset.symbol }}
                        <span
                            v-if="tickerFor(asset.symbol) !== null"
                            class="font-mono text-xs opacity-80"
                        >
                            {{
                                priceFormat(
                                    tickerFor(asset.symbol)!,
                                    asset.tick_size,
                                )
                            }}
                        </span>
                    </Button>
                </div>

                <div
                    class="flex items-center gap-3 text-sm"
                    data-testid="collector-status"
                >
                    <Badge
                        :variant="state === 'live' ? 'default' : 'destructive'"
                    >
                        {{ stateLabel[state] }}
                    </Badge>
                    <span class="text-muted-foreground">
                        <template v-if="lag === null"
                            >sem cotação ainda</template
                        >
                        <template v-else>atraso {{ lag }}s</template>
                    </span>
                </div>
            </div>

            <div
                v-if="current"
                class="flex min-h-96 flex-1 flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4"
            >
                <div class="flex items-baseline gap-3">
                    <h2 class="text-lg font-semibold">
                        {{ current.symbol }}
                    </h2>
                    <span v-if="lastPrice !== null" class="font-mono text-2xl">
                        {{ priceFormat(lastPrice, current.tick_size) }}
                        <span class="text-sm text-muted-foreground">
                            {{ current.quote_currency }}
                        </span>
                    </span>
                    <span
                        v-if="change !== null"
                        class="font-mono text-sm"
                        :class="change >= 0 ? 'text-green-600' : 'text-red-600'"
                    >
                        {{ change >= 0 ? '+' : '' }}{{ change.toFixed(2) }}%
                    </span>
                    <span class="text-xs text-muted-foreground">
                        candles de 1 minuto
                    </span>
                </div>

                <div class="min-h-80 flex-1">
                    <CandleChart
                        v-if="candles.length > 0"
                        :key="selected"
                        :candles="candles"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        Sem candles ainda. Inicie o coletor com
                        <code>php artisan market:stream</code>.
                    </p>
                </div>
            </div>
        </template>
    </div>
</template>
