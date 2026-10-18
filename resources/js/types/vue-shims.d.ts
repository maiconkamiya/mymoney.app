declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent;
    export default component;
}

// Módulos do ApexCharts que só registram um recurso no núcleo (sem tipos próprios).
declare module 'apexcharts/features/annotations';
