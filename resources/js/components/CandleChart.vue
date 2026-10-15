<script setup lang="ts">
import {
    CandlestickSeries,
    ColorType,
    createChart,
    type IChartApi,
    type ISeriesApi,
    type UTCTimestamp,
} from 'lightweight-charts';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Candle } from '@/types';

const props = defineProps<{
    candles: Candle[];
}>();

const container = ref<HTMLDivElement | null>(null);

let chart: IChartApi | null = null;
let series: ISeriesApi<'Candlestick'> | null = null;
let drawn: Candle[] = [];

const toBar = (candle: Candle) => ({
    time: candle.time as UTCTimestamp,
    open: candle.open,
    high: candle.high,
    low: candle.low,
    close: candle.close,
});

function paint(candles: Candle[]) {
    if (series === null) {
        return;
    }

    const last = candles.at(-1);
    const previousLast = drawn.at(-1);

    // Same series with only the tail changed: update in place so the chart keeps its scroll and zoom.
    if (
        last !== undefined &&
        previousLast !== undefined &&
        candles.length - drawn.length <= 1 &&
        candles[0]?.time === drawn[0]?.time
    ) {
        series.update(toBar(last));
    } else {
        series.setData(candles.map(toBar));
        chart?.timeScale().fitContent();
    }

    drawn = candles;
}

onMounted(() => {
    if (container.value === null) {
        return;
    }

    const styles = getComputedStyle(container.value);

    chart = createChart(container.value, {
        autoSize: true,
        layout: {
            background: { type: ColorType.Solid, color: 'transparent' },
            textColor: styles.color,
        },
        grid: {
            vertLines: { color: 'rgba(128, 128, 128, 0.12)' },
            horzLines: { color: 'rgba(128, 128, 128, 0.12)' },
        },
        rightPriceScale: { borderColor: 'rgba(128, 128, 128, 0.3)' },
        timeScale: {
            borderColor: 'rgba(128, 128, 128, 0.3)',
            timeVisible: true,
            secondsVisible: false,
        },
    });

    series = chart.addSeries(CandlestickSeries, {
        upColor: '#16a34a',
        downColor: '#dc2626',
        borderVisible: false,
        wickUpColor: '#16a34a',
        wickDownColor: '#dc2626',
    });

    paint(props.candles);
});

watch(() => props.candles, paint);

onBeforeUnmount(() => {
    chart?.remove();
    chart = null;
    series = null;
});
</script>

<template>
    <div ref="container" class="h-full min-h-80 w-full" />
</template>
