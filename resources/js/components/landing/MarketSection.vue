<script setup lang="ts">
import { ref, computed } from 'vue';
import {
    assets,
    ASSETS,
    candles,
    changePct,
    brl,
    pct,
    hora,
    type AssetId,
} from '@/lib/mercado';
import PriceChart from './PriceChart.vue';
import FeedStatus from './FeedStatus.vue';
import Reveal from './Reveal.vue';

const selected = ref<AssetId>('btc');
const a = computed(() => assets[selected.value]);
const series = computed(() => candles[selected.value].value);
const change = computed(() => changePct(selected.value));
const stats = computed(() => {
    const d = series.value;
    if (!d.length) return null;
    const hi = Math.max(...d.map((c) => c[2]));
    const lo = Math.min(...d.map((c) => c[3]));
    const vol = d.reduce((s, c) => s + c[5] * c[4], 0);
    return { hi, lo, vol };
});
</script>

<template>
    <section
        id="mercado"
        class="mx-auto max-w-[1400px] px-4 py-20 sm:px-6 md:py-24 lg:px-8"
    >
        <Reveal>
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div>
                    <h2
                        class="text-3xl font-semibold tracking-tight md:text-4xl"
                    >
                        O mercado, minuto a minuto
                    </h2>
                    <p class="mt-3 max-w-[60ch] text-muted-foreground">
                        O mesmo fluxo de negócios que os agentes recebem, nos
                        pares em real.
                    </p>
                </div>
                <div
                    class="flex rounded-full border border-border p-1"
                    role="tablist"
                    aria-label="Escolher ativo"
                >
                    <button
                        v-for="x in ASSETS"
                        :key="x.id"
                        type="button"
                        role="tab"
                        :aria-selected="selected === x.id"
                        class="press rounded-full px-4 py-1.5 text-sm font-medium"
                        :class="
                            selected === x.id
                                ? 'bg-foreground text-background'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="selected = x.id"
                    >
                        {{ x.name }}
                    </button>
                </div>
            </div>
        </Reveal>

        <div class="mt-10 grid gap-10 lg:grid-cols-[minmax(0,1fr)_300px]">
            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                    <p
                        :key="a.tick"
                        class="num text-4xl font-medium tracking-tight md:text-5xl"
                        :class="
                            a.tick
                                ? a.dir > 0
                                    ? 'flash-up'
                                    : 'flash-down'
                                : ''
                        "
                    >
                        {{ brl(a.price) }}
                    </p>
                    <p
                        class="num text-lg"
                        :class="change >= 0 ? 'text-up' : 'text-down'"
                    >
                        {{ pct(change) }}
                    </p>
                </div>
                <div class="mt-2"><FeedStatus /></div>
                <div class="mt-6">
                    <PriceChart :data="series" :up="change >= 0" />
                </div>
                <dl
                    v-if="stats"
                    class="mt-6 grid grid-cols-2 gap-6 sm:grid-cols-3"
                >
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Máxima em 3 h
                        </dt>
                        <dd class="num mt-1">{{ brl(stats.hi) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Mínima em 3 h
                        </dt>
                        <dd class="num mt-1">{{ brl(stats.lo) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Volume negociado
                        </dt>
                        <dd class="num mt-1">{{ brl(stats.vol, 0) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="min-w-0">
                <h3 class="font-medium">Últimos negócios</h3>
                <ol class="mt-4 grid gap-1" aria-live="off">
                    <li
                        v-for="t in a.trades.slice(0, 9)"
                        :key="t.id"
                        class="num grid grid-cols-[auto_1fr_auto] items-baseline gap-3 rounded-lg px-2 py-1.5 text-sm odd:bg-card"
                    >
                        <span class="text-muted-foreground">{{
                            hora(t.t)
                        }}</span>
                        <span
                            :class="
                                t.side === 'compra' ? 'text-up' : 'text-down'
                            "
                            >{{ t.side }}</span
                        >
                        <span>{{ brl(t.price) }}</span>
                    </li>
                </ol>
                <p
                    v-if="!a.trades.length"
                    class="mt-4 rounded-lg bg-card px-3 py-4 text-sm text-muted-foreground"
                >
                    Esperando o primeiro negócio. Ele aparece aqui assim que a
                    Binance registrar.
                </p>
            </div>
        </div>
    </section>
</template>
