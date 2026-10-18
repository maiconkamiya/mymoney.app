<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import type { ApexAxisChartSeries, ApexOptions } from 'apexcharts';
import { computed, ref } from 'vue';
import ApexChart from '@/components/charts/ApexChart.vue';
import { Button } from '@/components/ui/button';
import { baseOptions, mergeOptions, useChartPalette } from '@/lib/charts';
import { index, insight as insightRoute } from '@/routes/assets';
import { edit as systemSettings } from '@/routes/system-settings';

type Period = '24h' | '7d' | '1m' | '3m' | '1y';

type PeriodHistory = {
    change_pct: number;
    change: number;
    high: number;
    low: number;
    open: number;
    close: number;
    points: [number, number][];
};

type Sentiment = {
    value: number;
    label: string;
    updated_at: string;
    yesterday: number | null;
    last_week: number | null;
    last_month: number | null;
};

type Insight = {
    direction: 'up' | 'down' | 'flat';
    change_24h_pct: number;
    summary: string;
    points: string[];
    news_count: number;
    model: string;
    cost: number;
    created_at: string;
};

const props = defineProps<{
    asset: { id: number; symbol: string; base: string; name: string | null };
    insight: Insight | null;
    ai_enabled: boolean;
    can_edit_settings: boolean;
    history?: Partial<Record<Period, PeriodHistory>> | null;
    sentiment?: Sentiment | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ativos', href: index() }],
    },
});

const periods: { key: Period; label: string; long: string }[] = [
    { key: '24h', label: '24h', long: 'Últimas 24 horas' },
    { key: '7d', label: '7d', long: 'Últimos 7 dias' },
    { key: '1m', label: '1m', long: 'Último mês' },
    { key: '3m', label: '3m', long: 'Últimos 3 meses' },
    { key: '1y', label: '1y', long: 'Último ano' },
];

const period = ref<Period>('24h');
const palette = useChartPalette();
const refreshing = ref(false);

const current = computed(() => props.history?.[period.value] ?? null);
const day = computed(() => props.history?.['24h'] ?? null);

function price(value: number): string {
    const digits = value >= 1000 ? 2 : value >= 1 ? 4 : 8;

    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: digits,
    }).format(value);
}

function pct(value: number): string {
    return `${new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'exceptZero',
    }).format(value)}%`;
}

function tone(value: number): string {
    return value > 0
        ? 'text-up'
        : value < 0
          ? 'text-down'
          : 'text-muted-foreground';
}

function axisLabel(ms: number): string {
    const date = new Date(ms);

    if (period.value === '24h') {
        return date.toLocaleTimeString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    if (period.value === '1y') {
        return date.toLocaleDateString('pt-BR', {
            month: 'short',
            year: '2-digit',
        });
    }

    return date.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
    });
}

function tooltipLabel(ms: number): string {
    return new Date(ms).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: period.value === '1y' ? '2-digit' : undefined,
        hour:
            period.value === '24h' || period.value === '7d'
                ? '2-digit'
                : undefined,
        minute:
            period.value === '24h' || period.value === '7d'
                ? '2-digit'
                : undefined,
    });
}

const color = computed(() =>
    (current.value?.change_pct ?? 0) >= 0
        ? palette.value.up
        : palette.value.down,
);

const options = computed<ApexOptions>(() =>
    mergeOptions(baseOptions(palette.value), {
        chart: { type: 'area' },
        colors: [color.value],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0,
                opacityFrom: 0.28,
                opacityTo: 0,
                stops: [0, 100],
            },
        },
        xaxis: {
            labels: {
                formatter: (value: string | number) => axisLabel(Number(value)),
            },
        },
        yaxis: {
            labels: { formatter: (value: number) => price(value) },
        },
        tooltip: {
            x: { formatter: (value: number) => tooltipLabel(value) },
            y: { formatter: (value: number) => `${price(value)} USDT` },
        },
    }),
);

const series = computed<ApexAxisChartSeries>(() => [
    { name: props.asset.base, data: current.value?.points ?? [] },
]);

const direction = computed<'up' | 'down' | 'flat'>(() => {
    if (props.insight) {
        return props.insight.direction;
    }

    const change = day.value?.change_pct ?? 0;

    return change >= 0.5 ? 'up' : change <= -0.5 ? 'down' : 'flat';
});

const question = computed(() => {
    const name = props.asset.name
        ? `${props.asset.name} (${props.asset.base})`
        : props.asset.base;
    const verb = {
        up: 'está em alta',
        down: 'está em queda',
        flat: 'está de lado',
    }[direction.value];

    return `Por que ${name} ${verb} hoje?`;
});

/** Posição do ponteiro no semicírculo: 0 à esquerda, 100 à direita. */
function needle(value: number): { x: number; y: number } {
    const angle = Math.PI * (1 - value / 100);

    return { x: 60 + 44 * Math.cos(angle), y: 60 - 44 * Math.sin(angle) };
}

function sentimentTone(value: number): string {
    return value <= 24
        ? 'text-down'
        : value <= 46
          ? 'text-amber-400'
          : value <= 54
            ? 'text-muted-foreground'
            : 'text-up';
}

function when(iso: string): string {
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function refresh() {
    refreshing.value = true;
    router.post(
        insightRoute.url(props.asset.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['insight'],
            onFinish: () => (refreshing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="asset.base" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-muted-foreground">{{ asset.symbol }}</p>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ asset.name ?? asset.base }}
                </h1>
            </div>
            <div v-if="day" class="text-right" data-test="asset-price">
                <p class="text-2xl font-semibold tabular-nums">
                    {{ price(day.close) }}
                    <span class="text-sm font-normal text-muted-foreground"
                        >USDT</span
                    >
                </p>
                <p class="text-sm tabular-nums" :class="tone(day.change_pct)">
                    {{ pct(day.change_pct) }} em 24h
                </p>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-3">
            <section
                class="space-y-4 rounded-2xl border bg-card p-4 lg:col-span-2"
                data-test="price-history"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="font-medium">
                        Histórico de preços de {{ asset.base }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Velas públicas da Binance
                    </p>
                </div>

                <Deferred data="history">
                    <template #fallback>
                        <div class="grid grid-cols-5 gap-2">
                            <div
                                v-for="p in periods"
                                :key="p.key"
                                class="h-14 animate-pulse rounded-xl bg-muted/50"
                            />
                        </div>
                        <div
                            class="mt-4 h-64 animate-pulse rounded-xl bg-muted/30"
                        />
                    </template>

                    <p
                        v-if="!history"
                        class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Não foi possível buscar o histórico na Binance agora.
                        Recarregue a página em instantes.
                    </p>

                    <template v-else>
                        <div
                            class="grid grid-cols-5 gap-2"
                            role="tablist"
                            aria-label="Período"
                        >
                            <button
                                v-for="p in periods"
                                :key="p.key"
                                type="button"
                                role="tab"
                                :aria-selected="period === p.key"
                                :disabled="!history[p.key]"
                                class="rounded-xl border px-2 py-2 text-left transition-colors hover:bg-muted/40 disabled:opacity-40"
                                :class="
                                    period === p.key
                                        ? 'border-foreground/30 bg-muted/50'
                                        : 'border-transparent'
                                "
                                :data-test="`period-${p.key}`"
                                @click="period = p.key"
                            >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ p.label }}</span
                                >
                                <span
                                    class="block text-sm font-medium tabular-nums"
                                    :class="
                                        tone(history[p.key]?.change_pct ?? 0)
                                    "
                                >
                                    {{
                                        history[p.key]
                                            ? pct(history[p.key]!.change_pct)
                                            : '-'
                                    }}
                                </span>
                            </button>
                        </div>

                        <div v-if="current">
                            <ApexChart
                                :options="options"
                                :series="series"
                                :height="260"
                                :label="`Preço de ${asset.base}: ${periods.find((p) => p.key === period)?.long}`"
                            />
                            <dl
                                class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4"
                            >
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Variação
                                    </dt>
                                    <dd
                                        class="tabular-nums"
                                        :class="tone(current.change)"
                                    >
                                        {{ current.change > 0 ? '+' : ''
                                        }}{{ price(current.change) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Abertura
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{ price(current.open) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Máxima
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{ price(current.high) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Mínima
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{ price(current.low) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </template>
                </Deferred>
            </section>

            <section
                class="space-y-4 rounded-2xl border bg-card p-4"
                data-test="market-sentiment"
            >
                <h2 class="font-medium">Sentimento do mercado cripto</h2>

                <Deferred data="sentiment">
                    <template #fallback>
                        <div
                            class="mx-auto h-32 w-48 animate-pulse rounded-xl bg-muted/30"
                        />
                    </template>

                    <p
                        v-if="!sentiment"
                        class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        O índice de sentimento não respondeu agora.
                    </p>

                    <template v-else>
                        <div class="flex flex-col items-center">
                            <svg
                                viewBox="0 0 120 68"
                                class="w-48"
                                role="img"
                                :aria-label="`Índice ${sentiment.value} de 100: ${sentiment.label}`"
                            >
                                <defs>
                                    <linearGradient
                                        id="fg-gradient"
                                        x1="0"
                                        x2="1"
                                        y1="0"
                                        y2="0"
                                    >
                                        <stop
                                            offset="0%"
                                            :stop-color="palette.down"
                                        />
                                        <stop
                                            offset="50%"
                                            stop-color="#e3b341"
                                        />
                                        <stop
                                            offset="100%"
                                            :stop-color="palette.up"
                                        />
                                    </linearGradient>
                                </defs>
                                <path
                                    d="M 16 60 A 44 44 0 0 1 104 60"
                                    fill="none"
                                    stroke="url(#fg-gradient)"
                                    stroke-width="8"
                                    stroke-linecap="round"
                                />
                                <circle
                                    :cx="needle(sentiment.value).x"
                                    :cy="needle(sentiment.value).y"
                                    r="6"
                                    :fill="palette.card"
                                    :stroke="palette.foreground"
                                    stroke-width="2.5"
                                />
                            </svg>
                            <p
                                class="-mt-6 text-3xl font-semibold tabular-nums"
                            >
                                {{ sentiment.value }}
                            </p>
                            <p
                                class="text-sm font-medium"
                                :class="sentimentTone(sentiment.value)"
                            >
                                {{ sentiment.label }}
                            </p>
                        </div>

                        <dl class="grid grid-cols-3 gap-2 text-center text-sm">
                            <div
                                v-for="row in [
                                    {
                                        label: 'Ontem',
                                        value: sentiment.yesterday,
                                    },
                                    {
                                        label: 'Semana passada',
                                        value: sentiment.last_week,
                                    },
                                    {
                                        label: 'Mês passado',
                                        value: sentiment.last_month,
                                    },
                                ]"
                                :key="row.label"
                                class="rounded-xl bg-muted/40 px-2 py-2"
                            >
                                <dt class="text-xs text-muted-foreground">
                                    {{ row.label }}
                                </dt>
                                <dd
                                    class="font-medium tabular-nums"
                                    :class="
                                        row.value === null
                                            ? ''
                                            : sentimentTone(row.value)
                                    "
                                >
                                    {{ row.value ?? '-' }}
                                </dd>
                            </div>
                        </dl>

                        <p class="text-xs text-muted-foreground">
                            Índice Fear &amp; Greed (alternative.me), de 0 (medo
                            extremo) a 100 (ganância extrema). Vale para o
                            mercado cripto inteiro e muda uma vez por dia;
                            atualizado em {{ when(sentiment.updated_at) }}.
                        </p>
                    </template>
                </Deferred>
            </section>

            <section
                class="space-y-3 rounded-2xl border bg-card p-4 lg:col-span-2"
                data-test="asset-insight"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 class="font-medium">{{ question }}</h2>
                    <Button
                        v-if="ai_enabled"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="refreshing"
                        data-test="insight-refresh"
                        @click="refresh"
                    >
                        {{
                            refreshing
                                ? 'Escrevendo...'
                                : insight
                                  ? 'Atualizar análise'
                                  : 'Gerar análise'
                        }}
                    </Button>
                </div>

                <template v-if="insight">
                    <p class="text-sm leading-relaxed">{{ insight.summary }}</p>
                    <ul
                        v-if="insight.points.length"
                        class="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
                    >
                        <li v-for="point in insight.points" :key="point">
                            {{ point }}
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Escrita por IA em {{ when(insight.created_at) }} com as
                        velas, o sentimento e
                        {{
                            insight.news_count === 1
                                ? '1 manchete'
                                : `${insight.news_count} manchetes`
                        }}
                        coletadas. Custo US$
                        {{
                            insight.cost.toLocaleString('pt-BR', {
                                minimumFractionDigits: 4,
                                maximumFractionDigits: 4,
                            })
                        }}. Não é recomendação de compra ou venda.
                    </p>
                </template>

                <p v-else-if="ai_enabled" class="text-sm text-muted-foreground">
                    Ainda não há análise deste ativo hoje. Ela é escrita todo
                    dia de manhã para os ativos acompanhados, ou agora pelo
                    botão.
                </p>

                <p v-else class="text-sm text-muted-foreground">
                    A análise do dia usa IA e está desligada.
                    <Link
                        v-if="can_edit_settings"
                        :href="systemSettings()"
                        class="text-foreground underline underline-offset-4"
                        >Ligar em Custos e serviços</Link
                    >
                    <template v-else
                        >O administrador liga em Custos e serviços.</template
                    >
                </p>
            </section>
        </div>
    </div>
</template>
