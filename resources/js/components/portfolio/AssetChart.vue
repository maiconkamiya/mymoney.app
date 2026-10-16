<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ChartCandle } from '@/types';

const props = defineProps<{
    data: ChartCandle[];
    up: boolean;
    avgPrice?: number | null;
    stopPrice?: number | null;
    format: (value: number) => string;
}>();

const box = ref<HTMLElement | null>(null);
const width = ref(360);
let observer: ResizeObserver | null = null;

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(240, Math.round(entry.contentRect.width));
    });

    if (box.value) {
        observer.observe(box.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

const PAD = { top: 12, right: 8, bottom: 22, left: 8 };
const HEIGHT = 168;

const geo = computed(() => {
    const d = props.data;

    if (d.length < 2) {
        return null;
    }

    const W = width.value;
    const refs = [props.avgPrice, props.stopPrice].filter(
        (v): v is number => typeof v === 'number',
    );
    const lo = Math.min(...d.map((c) => c[3]), ...refs);
    const hi = Math.max(...d.map((c) => c[2]), ...refs);
    const span = hi - lo || 1;
    const x = (i: number) =>
        PAD.left + (i / (d.length - 1)) * (W - PAD.left - PAD.right);
    const y = (v: number) =>
        PAD.top + (1 - (v - lo) / span) * (HEIGHT - PAD.top - PAD.bottom);
    const line = d
        .map(
            (c, i) =>
                `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(c[4]).toFixed(1)}`,
        )
        .join('');
    const base = HEIGHT - PAD.bottom;
    const area = `${line}L${x(d.length - 1).toFixed(1)},${base}L${PAD.left},${base}Z`;
    const time = (i: number) =>
        new Date(d[i][0]).toLocaleTimeString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
        });
    const last = d[d.length - 1];

    return {
        W,
        line,
        area,
        base,
        first: { x: PAD.left, label: time(0) },
        lastLabel: time(d.length - 1),
        end: { x: x(d.length - 1), y: y(last[4]) },
        avg: typeof props.avgPrice === 'number' ? y(props.avgPrice) : null,
        stop: typeof props.stopPrice === 'number' ? y(props.stopPrice) : null,
    };
});

const color = computed(() => (props.up ? 'var(--up)' : 'var(--down)'));
const gradientId = `fill-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <div ref="box" class="w-full overflow-hidden">
        <svg
            v-if="geo"
            :viewBox="`0 0 ${geo.W} ${HEIGHT}`"
            class="block h-auto w-full"
            role="img"
            aria-label="Preço de fechamento por minuto"
        >
            <defs>
                <linearGradient :id="gradientId" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" :stop-color="color" stop-opacity="0.2" />
                    <stop offset="100%" :stop-color="color" stop-opacity="0" />
                </linearGradient>
            </defs>

            <line
                :x1="PAD.left"
                :x2="geo.W - PAD.right"
                :y1="geo.base"
                :y2="geo.base"
                stroke="var(--border)"
            />

            <g v-if="geo.avg !== null">
                <line
                    :x1="PAD.left"
                    :x2="geo.W - PAD.right"
                    :y1="geo.avg"
                    :y2="geo.avg"
                    stroke="var(--muted-foreground)"
                    stroke-dasharray="3 5"
                />
                <text
                    :x="PAD.left + 2"
                    :y="geo.avg - 4"
                    fill="var(--muted-foreground)"
                    font-size="11"
                >
                    médio {{ format(avgPrice as number) }}
                </text>
            </g>
            <g v-if="geo.stop !== null">
                <line
                    :x1="PAD.left"
                    :x2="geo.W - PAD.right"
                    :y1="geo.stop"
                    :y2="geo.stop"
                    stroke="var(--down)"
                    stroke-dasharray="3 5"
                    opacity="0.7"
                />
                <text
                    :x="PAD.left + 2"
                    :y="geo.stop - 4"
                    fill="var(--down)"
                    font-size="11"
                >
                    stop {{ format(stopPrice as number) }}
                </text>
            </g>

            <path :d="geo.area" :fill="`url(#${gradientId})`" />
            <path
                :d="geo.line"
                fill="none"
                :stroke="color"
                stroke-width="2"
                stroke-linejoin="round"
            />
            <circle
                :cx="geo.end.x"
                :cy="geo.end.y"
                r="8"
                :fill="color"
                opacity="0.16"
            />
            <circle :cx="geo.end.x" :cy="geo.end.y" r="3.5" :fill="color" />

            <text
                :x="geo.first.x"
                :y="HEIGHT - 6"
                fill="var(--muted-foreground)"
                font-size="11"
            >
                {{ geo.first.label }}
            </text>
            <text
                :x="geo.W - PAD.right"
                :y="HEIGHT - 6"
                fill="var(--muted-foreground)"
                font-size="11"
                text-anchor="end"
            >
                {{ geo.lastLabel }}
            </text>
        </svg>
        <div
            v-else
            class="flex h-[168px] items-center justify-center rounded-xl bg-muted/40 text-xs text-muted-foreground"
        >
            Sem cotações gravadas ainda.
        </div>
    </div>
</template>
