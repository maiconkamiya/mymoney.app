<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import type { Candle } from '@/lib/mercado';
import { brl } from '@/lib/mercado';

// Linha de fechamento por minuto, desenhada numa única escala; eixos com valores que o gráfico atinge.
const props = defineProps<{ data: Candle[]; up: boolean }>();

// A largura do desenho acompanha o container, para os rótulos manterem 12 px no celular.
const box = ref<HTMLElement | null>(null);
const width = ref(1000);
let ro: ResizeObserver | null = null;
onMounted(() => {
    ro = new ResizeObserver(
        ([e]) => (width.value = Math.max(300, Math.round(e.contentRect.width))),
    );
    if (box.value) ro.observe(box.value);
});
onBeforeUnmount(() => ro?.disconnect());

const PAD = { top: 16, right: 92, bottom: 28, left: 8 };

const geo = computed(() => {
    const W = width.value;
    const H = W < 640 ? 220 : 320;
    const d = props.data;
    if (d.length < 2) return null;
    const lo = Math.min(...d.map((c) => c[3]));
    const hi = Math.max(...d.map((c) => c[2]));
    const span = hi - lo || 1;
    const x = (i: number) =>
        PAD.left + (i / (d.length - 1)) * (W - PAD.left - PAD.right);
    const y = (v: number) =>
        PAD.top + (1 - (v - lo) / span) * (H - PAD.top - PAD.bottom);
    const line = d
        .map(
            (c, i) =>
                `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(c[4]).toFixed(1)}`,
        )
        .join('');
    const area = `${line}L${x(d.length - 1).toFixed(1)},${H - PAD.bottom}L${PAD.left},${H - PAD.bottom}Z`;
    const ticks = [hi, lo + span / 2, lo].map((v) => ({ v, y: y(v) }));
    const times = [0, Math.floor((d.length - 1) / 2), d.length - 1].map(
        (i) => ({
            x: x(i),
            label: new Date(d[i][0]).toLocaleTimeString('pt-BR', {
                hour: '2-digit',
                minute: '2-digit',
            }),
        }),
    );
    const last = d[d.length - 1];
    return {
        W,
        H,
        line,
        area,
        ticks,
        times,
        end: { x: x(d.length - 1), y: y(last[4]) },
    };
});
</script>

<template>
    <div ref="box" class="w-full overflow-hidden">
        <svg
            v-if="geo"
            :viewBox="`0 0 ${geo.W} ${geo.H}`"
            class="h-auto w-full"
            role="img"
            aria-label="Preço de fechamento por minuto nas últimas três horas"
        >
            <defs>
                <linearGradient id="area-fill" x1="0" x2="0" y1="0" y2="1">
                    <stop
                        offset="0%"
                        :stop-color="up ? 'var(--up)' : 'var(--down)'"
                        stop-opacity="0.22"
                    />
                    <stop
                        offset="100%"
                        :stop-color="up ? 'var(--up)' : 'var(--down)'"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>
            <g v-for="t in geo.ticks" :key="t.v">
                <line
                    :x1="PAD.left"
                    :x2="geo.W - PAD.right"
                    :y1="t.y"
                    :y2="t.y"
                    stroke="var(--border)"
                    stroke-dasharray="2 6"
                />
                <text
                    :x="geo.W - PAD.right + 10"
                    :y="t.y + 4"
                    fill="var(--muted-foreground)"
                    font-size="12"
                    class="num"
                >
                    {{ brl(t.v, 0) }}
                </text>
            </g>
            <text
                v-for="(t, i) in geo.times"
                :key="i"
                :x="t.x"
                :y="geo.H - 6"
                fill="var(--muted-foreground)"
                font-size="12"
                class="num"
                :text-anchor="i === 0 ? 'start' : i === 2 ? 'end' : 'middle'"
            >
                {{ t.label }}
            </text>
            <path :d="geo.area" fill="url(#area-fill)" />
            <path
                :d="geo.line"
                fill="none"
                :stroke="up ? 'var(--up)' : 'var(--down)'"
                stroke-width="2"
                stroke-linejoin="round"
            />
            <circle
                :cx="geo.end.x"
                :cy="geo.end.y"
                r="9"
                :fill="up ? 'var(--up)' : 'var(--down)'"
                opacity="0.18"
            />
            <circle
                :cx="geo.end.x"
                :cy="geo.end.y"
                r="4"
                :fill="up ? 'var(--up)' : 'var(--down)'"
            />
        </svg>
        <!-- Esqueleto com a forma do gráfico enquanto não há candles. -->
        <div
            v-else
            class="aspect-[1000/320] w-full animate-pulse rounded-2xl bg-muted"
        />
    </div>
</template>
