<script setup lang="ts">
import type ApexCharts from 'apexcharts';
import type { ApexAxisChartSeries, ApexOptions } from 'apexcharts';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

/**
 * Componente base dos gráficos: cria o ApexCharts uma vez e, a cada dado novo,
 * só atualiza a série (`updateSeries`) ou as opções (`updateOptions`), sem
 * recriar o gráfico. As opções já vêm com o tema (ver `baseOptions`).
 * O ApexCharts é carregado sob demanda para não pesar o resto do app.
 */
const props = defineProps<{
    options: ApexOptions;
    series: ApexAxisChartSeries;
    height: number;
    label: string;
}>();

const el = ref<HTMLElement | null>(null);
const chart = shallowRef<ApexCharts | null>(null);
let disposed = false;
let optionsChanged = false;
let queued = false;

onMounted(async () => {
    // Só o núcleo e os tipos que o app usa (linha, área, velas e linhas de preço).
    const [{ default: Apex }] = await Promise.all([
        import('apexcharts/core'),
        import('apexcharts/line'),
        import('apexcharts/area'),
        import('apexcharts/candlestick'),
        import('apexcharts/features/annotations'),
    ]);

    if (disposed || !el.value) {
        return;
    }

    const instance = new Apex(el.value, {
        ...props.options,
        chart: { ...props.options.chart, height: props.height },
        series: props.series,
    });
    await instance.render();

    if (disposed) {
        instance.destroy();

        return;
    }

    chart.value = instance;
});

onBeforeUnmount(() => {
    disposed = true;
    chart.value?.destroy();
    chart.value = null;
});

/** Junta mudanças do mesmo tick numa única atualização. */
function flush() {
    queued = false;
    const instance = chart.value;

    if (!instance) {
        return;
    }

    if (optionsChanged) {
        optionsChanged = false;
        void instance.updateOptions(
            {
                ...props.options,
                chart: { ...props.options.chart, height: props.height },
                series: props.series,
            },
            false,
            false,
        );

        return;
    }

    void instance.updateSeries(props.series, false);
}

function schedule() {
    if (!queued) {
        queued = true;
        queueMicrotask(flush);
    }
}

watch(
    () => [props.options, props.height],
    () => {
        optionsChanged = true;
        schedule();
    },
);
watch(() => props.series, schedule);
</script>

<template>
    <div
        ref="el"
        class="mm-chart w-full"
        role="img"
        :aria-label="label"
        :style="{ minHeight: `${height}px` }"
    />
</template>
