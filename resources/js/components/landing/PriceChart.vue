<script setup lang="ts">
import type { ApexAxisChartSeries, ApexOptions } from 'apexcharts';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import ApexChart from '@/components/charts/ApexChart.vue';
import { baseOptions, mergeOptions, useChartPalette } from '@/lib/charts';
import type { Candle } from '@/lib/mercado';
import { brl } from '@/lib/mercado';

// Linha de fechamento por minuto; eixo de preço à direita com valores que o gráfico atinge.
const props = defineProps<{ data: Candle[]; up: boolean }>();

const palette = useChartPalette();
const wide = useMediaQuery('(min-width: 640px)');
const height = computed(() => (wide.value ? 320 : 220));

const options = computed<ApexOptions>(() => {
    const p = palette.value;

    return mergeOptions(baseOptions(p), {
        chart: { type: 'area' },
        colors: [props.up ? p.up : p.down],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0,
                opacityFrom: 0.24,
                opacityTo: 0,
                stops: [0, 100],
            },
        },
        markers: { size: 0, hover: { size: 4 } },
        grid: { padding: { left: 16, right: 4 } },
        xaxis: { tickAmount: 3, labels: { hideOverlappingLabels: true } },
        yaxis: {
            opposite: true,
            tickAmount: 2,
            labels: {
                style: { fontSize: '12px' },
                formatter: (value: number) => brl(value, 0),
            },
        },
        tooltip: { y: { formatter: (value: number) => brl(value) } },
    });
});

const series = computed<ApexAxisChartSeries>(() => [
    { name: 'Preço', data: props.data.map((c) => [c[0], c[4]]) },
]);
</script>

<template>
    <div class="w-full overflow-hidden">
        <ApexChart
            v-if="data.length >= 2"
            :options="options"
            :series="series"
            :height="height"
            label="Preço de fechamento por minuto nas últimas três horas"
        />
        <!-- Esqueleto com a forma do gráfico enquanto não há candles. -->
        <div
            v-else
            class="w-full animate-pulse rounded-2xl bg-muted"
            :style="{ height: `${height}px` }"
        />
    </div>
</template>
