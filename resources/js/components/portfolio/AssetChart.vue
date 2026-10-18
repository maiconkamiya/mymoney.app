<script setup lang="ts">
import type { ApexAxisChartSeries, ApexOptions } from 'apexcharts';
import { computed } from 'vue';
import ApexChart from '@/components/charts/ApexChart.vue';
import {
    baseOptions,
    hourMinute,
    mergeOptions,
    priceLine,
    useChartPalette,
} from '@/lib/charts';
import type { ChartCandle } from '@/types';

export type AssetChartMode = 'line' | 'candles';

const props = defineProps<{
    data: ChartCandle[];
    up: boolean;
    avgPrice?: number | null;
    stopPrice?: number | null;
    targetPrice?: number | null;
    targetLabel?: string;
    ceilingPrice?: number | null;
    format: (value: number) => string;
    mode?: AssetChartMode;
}>();

const HEIGHT = 168;
const palette = useChartPalette();
const mode = computed<AssetChartMode>(() => props.mode ?? 'line');

const num = (v: number | null | undefined): number | null =>
    typeof v === 'number' ? v : null;

/**
 * Tudo o que muda as opções numa só chave de texto: enquanto as linhas e a cor
 * não mudam, as opções não mudam e cada minuto novo só troca a série.
 */
const linesKey = computed(() =>
    JSON.stringify({
        up: props.up,
        avg: num(props.avgPrice),
        stop: num(props.stopPrice),
        target: num(props.targetPrice),
        targetLabel: props.targetLabel ?? 'alvo',
        ceiling: num(props.ceilingPrice),
        avgText:
            num(props.avgPrice) === null
                ? ''
                : props.format(props.avgPrice as number),
        stopText:
            num(props.stopPrice) === null
                ? ''
                : props.format(props.stopPrice as number),
        targetText:
            num(props.targetPrice) === null
                ? ''
                : props.format(props.targetPrice as number),
        ceilingText:
            num(props.ceilingPrice) === null
                ? ''
                : props.format(props.ceilingPrice as number),
    }),
);

/**
 * Faixa do eixo de preço com as linhas de médio, stop, alvo e teto sempre
 * visíveis. Arredondada para um passo fixo: só muda quando o preço sai dela,
 * então a maioria dos minutos novos troca só a série.
 */
const range = computed(() => {
    const refs = [
        props.avgPrice,
        props.stopPrice,
        props.targetPrice,
        props.ceilingPrice,
    ].filter((v): v is number => typeof v === 'number');
    const lo = Math.min(...props.data.map((c) => c[3]), ...refs);
    const hi = Math.max(...props.data.map((c) => c[2]), ...refs);
    const span = hi - lo || Math.abs(hi) * 0.001 || 1;
    const step = 10 ** Math.floor(Math.log10(span)) / 4;

    return `${Math.floor((lo - span * 0.04) / step) * step}|${Math.ceil((hi + span * 0.04) / step) * step}`;
});

const options = computed<ApexOptions>(() => {
    const [yMin, yMax] = range.value.split('|').map(Number);
    const k = JSON.parse(linesKey.value) as {
        up: boolean;
        avg: number | null;
        stop: number | null;
        target: number | null;
        targetLabel: string;
        ceiling: number | null;
        avgText: string;
        stopText: string;
        targetText: string;
        ceilingText: string;
    };
    const p = palette.value;
    const color = k.up ? p.up : p.down;
    const lines = [
        k.avg !== null &&
            priceLine(k.avg, p.muted, `médio ${k.avgText}`, {
                background: p.card,
            }),
        k.stop !== null &&
            priceLine(k.stop, p.down, `stop ${k.stopText}`, {
                opacity: 0.7,
                background: p.card,
            }),
        k.target !== null &&
            priceLine(k.target, p.up, `${k.targetLabel} ${k.targetText}`, {
                opacity: 0.7,
                background: p.card,
            }),
        k.ceiling !== null &&
            priceLine(k.ceiling, p.foreground, `teto ${k.ceilingText}`, {
                dash: 2,
                opacity: 0.5,
                align: 'right',
                background: p.card,
            }),
    ].filter((line) => line !== false);
    const candles = mode.value === 'candles';

    return mergeOptions(baseOptions(p), {
        chart: { type: candles ? 'candlestick' : 'area' },
        colors: [color],
        stroke: { width: candles ? 1 : 2 },
        fill: candles
            ? { type: 'solid', opacity: 1 }
            : {
                  type: 'gradient',
                  gradient: {
                      shadeIntensity: 0,
                      opacityFrom: 0.22,
                      opacityTo: 0,
                      stops: [0, 100],
                  },
              },
        plotOptions: {
            candlestick: {
                colors: { upward: p.up, downward: p.down },
                wick: { useFillColor: true },
            },
        },
        markers: { size: 0, hover: { size: 3 } },
        grid: { show: false, padding: { left: 16, right: 16 } },
        xaxis: { tickAmount: 2, labels: { hideOverlappingLabels: true } },
        yaxis: { show: false, min: yMin, max: yMax },
        annotations: { yaxis: lines },
        tooltip: {
            x: { formatter: (value: number) => hourMinute(value) },
            y: { formatter: (value: number) => props.format(value) },
        },
    });
});

const series = computed<ApexAxisChartSeries>(() =>
    mode.value === 'candles'
        ? [
              {
                  name: 'Vela de 1 min',
                  data: props.data.map((c) => ({
                      x: c[0],
                      y: [c[1], c[2], c[3], c[4]],
                  })),
              },
          ]
        : [
              {
                  name: 'Preço',
                  data: props.data.map((c) => [c[0], c[4]]),
              },
          ],
);
</script>

<template>
    <div class="w-full overflow-hidden" data-testid="asset-chart-plot">
        <ApexChart
            v-if="data.length >= 2"
            :key="mode"
            :options="options"
            :series="series"
            :height="HEIGHT"
            :label="
                mode === 'candles'
                    ? 'Velas de 1 minuto'
                    : 'Preço de fechamento por minuto'
            "
        />
        <div
            v-else
            class="flex h-[168px] items-center justify-center rounded-xl bg-muted/40 text-xs text-muted-foreground"
        >
            Sem cotações gravadas ainda.
        </div>
    </div>
</template>
