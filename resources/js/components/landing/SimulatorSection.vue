<script setup lang="ts">
import { ref, computed } from 'vue';
import daily from '@/data/mercado/btc-1d.json';
import { brl, pct } from '@/lib/mercado';
import Reveal from './Reveal.vue';

// Fechamentos diários reais do BTC/BRL na Binance (até 16/10/2026).
const closes = daily as [number, number][];
const periods = [30, 90, 180, 365];

const amountText = ref('1000');
const days = ref(90);

const amount = computed(() =>
    Number(amountText.value.replace(/\./g, '').replace(',', '.')),
);
const error = computed(() => {
    if (!amountText.value.trim()) return 'Informe quanto quer simular.';
    if (!Number.isFinite(amount.value))
        return 'Use só números, como 1000 ou 2500,50.';
    if (amount.value < 100) return 'O valor mínimo da simulação é R$ 100.';
    if (amount.value > 1_000_000)
        return 'O valor máximo da simulação é R$ 1.000.000.';
    return '';
});

const result = computed(() => {
    if (error.value) return null;
    const slice = closes.slice(-(days.value + 1));
    const qty = amount.value / slice[0][1];
    const curve = slice.map(([, c]) => qty * c);
    let peak = curve[0];
    let worst = 0;
    for (const v of curve) {
        peak = Math.max(peak, v);
        worst = Math.min(worst, v / peak - 1);
    }
    const final = curve[curve.length - 1];
    return {
        final,
        ret: (final / amount.value - 1) * 100,
        worst: worst * 100,
        curve,
        from: slice[0][0],
    };
});

const spark = computed(() => {
    const c = result.value?.curve;
    if (!c) return '';
    const lo = Math.min(...c);
    const hi = Math.max(...c);
    return c
        .map(
            (v, i) =>
                `${i ? 'L' : 'M'}${((i / (c.length - 1)) * 600).toFixed(1)},${(8 + (1 - (v - lo) / (hi - lo || 1)) * 104).toFixed(1)}`,
        )
        .join('');
});
</script>

<template>
    <section
        id="simulador"
        class="mx-auto max-w-[1400px] px-4 py-20 sm:px-6 md:py-24 lg:px-8"
    >
        <div
            class="grid gap-10 rounded-2xl border border-border bg-card p-6 md:p-10 lg:grid-cols-12 lg:gap-12"
        >
            <Reveal class="lg:col-span-5">
                <h2 class="text-3xl font-semibold tracking-tight md:text-4xl">
                    Teste com valor e prazo
                </h2>
                <p class="mt-3 max-w-[48ch] text-muted-foreground">
                    Veja o que teria acontecido comprando Bitcoin e segurando,
                    com os preços reais de cada dia.
                </p>

                <form class="mt-8 grid gap-6" @submit.prevent>
                    <div class="grid gap-2">
                        <label for="valor" class="text-sm font-medium"
                            >Valor investido (R$)</label
                        >
                        <input
                            id="valor"
                            v-model="amountText"
                            inputmode="decimal"
                            autocomplete="off"
                            class="num rounded-lg border bg-background px-4 py-3 text-lg outline-none focus-visible:outline-2 focus-visible:outline-primary"
                            :class="error ? 'border-down' : 'border-border'"
                            :aria-invalid="!!error"
                            aria-describedby="valor-ajuda valor-erro"
                        />
                        <p
                            id="valor-ajuda"
                            class="text-sm text-muted-foreground"
                        >
                            Entre R$ 100 e R$ 1.000.000.
                        </p>
                        <p
                            v-if="error"
                            id="valor-erro"
                            class="text-sm text-down"
                        >
                            {{ error }}
                        </p>
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">
                            Período
                        </legend>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="p in periods"
                                :key="p"
                                class="press cursor-pointer"
                            >
                                <input
                                    v-model="days"
                                    type="radio"
                                    name="periodo"
                                    :value="p"
                                    class="peer sr-only"
                                />
                                <span
                                    class="num block rounded-full border border-border px-4 py-2 text-sm peer-checked:border-foreground peer-checked:bg-foreground peer-checked:text-background peer-focus-visible:outline-2 peer-focus-visible:outline-primary"
                                >
                                    {{ p }} dias
                                </span>
                            </label>
                        </div>
                    </fieldset>
                </form>
            </Reveal>

            <div class="min-w-0 lg:col-span-7" aria-live="polite">
                <div v-if="result" class="grid h-full content-between gap-8">
                    <dl class="grid grid-cols-2 gap-6 sm:grid-cols-3">
                        <div>
                            <dt class="text-sm text-muted-foreground">
                                Valor hoje
                            </dt>
                            <dd
                                class="num mt-1 text-2xl font-medium md:text-3xl"
                            >
                                {{ brl(result.final) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-muted-foreground">
                                Resultado
                            </dt>
                            <dd
                                class="num mt-1 text-2xl font-medium md:text-3xl"
                                :class="
                                    result.ret >= 0 ? 'text-up' : 'text-down'
                                "
                            >
                                {{ pct(result.ret) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-muted-foreground">
                                Pior queda no caminho
                            </dt>
                            <dd
                                class="num mt-1 text-2xl font-medium text-down md:text-3xl"
                            >
                                {{ pct(result.worst) }}
                            </dd>
                        </div>
                    </dl>
                    <div>
                        <svg
                            viewBox="0 0 600 120"
                            class="h-auto w-full"
                            role="img"
                            :aria-label="`Valor da carteira dia a dia nos últimos ${days} dias`"
                        >
                            <path
                                :d="spark"
                                fill="none"
                                :stroke="
                                    result.ret >= 0
                                        ? 'var(--up)'
                                        : 'var(--down)'
                                "
                                stroke-width="2"
                                stroke-linejoin="round"
                                vector-effect="non-scaling-stroke"
                            />
                        </svg>
                        <p class="mt-3 text-sm text-muted-foreground">
                            Desde
                            {{
                                new Date(result.from).toLocaleDateString(
                                    'pt-BR',
                                )
                            }}. Resultado passado não garante resultado futuro.
                            No app, o mesmo teste roda com os agentes e a guarda
                            de risco.
                        </p>
                    </div>
                </div>
                <div
                    v-else
                    class="grid h-full place-items-center rounded-2xl border border-dashed border-border p-10 text-center text-muted-foreground"
                >
                    <p>Corrija o valor ao lado para ver a simulação.</p>
                </div>
            </div>
        </div>
    </section>
</template>
