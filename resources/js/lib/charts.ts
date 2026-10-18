import type { ApexOptions } from 'apexcharts';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/** Cores do tema lidas das variáveis CSS, já resolvidas (o SVG do ApexCharts não entende `var()`). */
export type ChartPalette = {
    dark: boolean;
    up: string;
    down: string;
    foreground: string;
    muted: string;
    border: string;
    primary: string;
    card: string;
    font: string;
    mono: string;
};

const FALLBACK: ChartPalette = {
    dark: true,
    up: '#3fb68b',
    down: '#ef6461',
    foreground: '#eef0f4',
    muted: '#9aa3b2',
    border: '#262b35',
    primary: '#6d8dff',
    card: '#13161c',
    font: 'Geist Variable, ui-sans-serif, system-ui, sans-serif',
    mono: 'Geist Mono Variable, ui-monospace, monospace',
};

export function readPalette(): ChartPalette {
    if (typeof document === 'undefined') {
        return FALLBACK;
    }

    const css = getComputedStyle(document.documentElement);
    const pick = (name: string, fallback: string) =>
        css.getPropertyValue(name).trim() || fallback;

    return {
        dark: document.documentElement.classList.contains('dark'),
        up: pick('--up', FALLBACK.up),
        down: pick('--down', FALLBACK.down),
        foreground: pick('--foreground', FALLBACK.foreground),
        muted: pick('--muted-foreground', FALLBACK.muted),
        border: pick('--border', FALLBACK.border),
        primary: pick('--primary', FALLBACK.primary),
        card: pick('--card', FALLBACK.card),
        font: pick('--font-sans', FALLBACK.font),
        mono: pick('--font-mono', FALLBACK.mono),
    };
}

/** Paleta que acompanha a troca de tema claro/escuro (classe `dark` no `<html>`). */
export function useChartPalette() {
    const palette = ref<ChartPalette>(readPalette());
    let observer: MutationObserver | null = null;

    onMounted(() => {
        palette.value = readPalette();
        observer = new MutationObserver(() => {
            const next = readPalette();

            if (JSON.stringify(next) !== JSON.stringify(palette.value)) {
                palette.value = next;
            }
        });
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style'],
        });
    });

    onBeforeUnmount(() => observer?.disconnect());

    return palette;
}

export function hourMinute(ms: number): string {
    return new Date(ms).toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return (
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        Object.getPrototypeOf(value) === Object.prototype
    );
}

/** Junta opções do ApexCharts: objetos se combinam, listas e valores são trocados. */
export function mergeOptions<T extends object>(base: T, extra: object): T {
    const out: Record<string, unknown> = {
        ...(base as Record<string, unknown>),
    };

    for (const [key, value] of Object.entries(extra)) {
        const current = out[key];
        out[key] =
            isPlainObject(current) && isPlainObject(value)
                ? mergeOptions<Record<string, unknown>>(current, value)
                : value;
    }

    return out as T;
}

/** Visual comum a todos os gráficos do app: fundo transparente, Geist, sem barra de ferramentas. */
export function baseOptions(p: ChartPalette): ApexOptions {
    return {
        chart: {
            background: 'transparent',
            fontFamily: p.font,
            foreColor: p.muted,
            parentHeightOffset: 0,
            toolbar: { show: false },
            zoom: { enabled: false },
            selection: { enabled: false },
            animations: {
                enabled: true,
                speed: 420,
                animateGradually: { enabled: false },
                dynamicAnimation: { enabled: false },
            },
        },
        theme: { mode: p.dark ? 'dark' : 'light' },
        dataLabels: { enabled: false },
        legend: { show: false },
        grid: {
            borderColor: p.border,
            strokeDashArray: 4,
            padding: { top: 0, right: 8, bottom: 0, left: 8 },
            xaxis: { lines: { show: false } },
        },
        stroke: { width: 2, curve: 'straight' },
        xaxis: {
            type: 'datetime',
            axisBorder: { show: true, color: p.border },
            axisTicks: { show: false },
            crosshairs: { stroke: { color: p.border, dashArray: 3 } },
            tooltip: { enabled: false },
            labels: {
                datetimeUTC: false,
                style: { fontSize: '11px', fontFamily: p.mono },
                formatter: (value: string | number) =>
                    hourMinute(Number(value)),
            },
        },
        yaxis: {
            labels: { style: { fontSize: '11px', fontFamily: p.mono } },
        },
        tooltip: {
            theme: p.dark ? 'dark' : 'light',
            style: { fontFamily: p.font, fontSize: '12px' },
            x: { formatter: (value: number) => hourMinute(value) },
        },
        states: {
            hover: { filter: { type: 'none' } },
            active: { filter: { type: 'none' } },
        },
    };
}

/** Linha horizontal tracejada com rótulo (preço médio, stop, alvo, teto). */
export function priceLine(
    y: number,
    color: string,
    text: string,
    options: {
        dash?: number;
        opacity?: number;
        align?: 'left' | 'right';
        /** Fundo do rótulo (cor do card), para a linha do preço não passar por cima do texto. */
        background?: string;
    } = {},
): NonNullable<NonNullable<ApexOptions['annotations']>['yaxis']>[number] {
    const right = options.align === 'right';

    return {
        y,
        borderColor: color,
        strokeDashArray: options.dash ?? 4,
        opacity: options.opacity ?? 0.8,
        label: {
            text,
            position: right ? 'right' : 'left',
            textAnchor: right ? 'end' : 'start',
            offsetX: right ? -2 : 2,
            offsetY: 2,
            borderWidth: 0,
            style: {
                background: options.background ?? 'transparent',
                color,
                fontSize: '11px',
                padding: { left: 3, right: 3, top: 1, bottom: 1 },
            },
        },
    };
}
