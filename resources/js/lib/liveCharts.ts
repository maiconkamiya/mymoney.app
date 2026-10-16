import type { ChartCandle } from '@/types';

const MAX_CANDLES = 90;

export type Kline = {
    t: number;
    o: string;
    h: string;
    l: string;
    c: string;
    v: string;
};

/** Candle de 1 minuto transmitido pelo servidor no canal `mercado`. */
export type ServerCandle = {
    open_at: string;
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
};

export function klineFromServer(c: ServerCandle): Kline {
    return {
        t: Date.parse(c.open_at),
        o: String(c.open),
        h: String(c.high),
        l: String(c.low),
        c: String(c.close),
        v: String(c.volume),
    };
}

/**
 * Aplica um candle de 1 minuto vindo da Binance à série: atualiza o candle
 * aberto ou acrescenta o novo. Candle mais antigo que o último é ignorado.
 */
export function applyKline(series: ChartCandle[], k: Kline): ChartCandle[] {
    const candle: ChartCandle = [
        k.t,
        Number(k.o),
        Number(k.h),
        Number(k.l),
        Number(k.c),
        Number(k.v),
    ];
    const last = series[series.length - 1];

    if (last && candle[0] < last[0]) {
        return series;
    }

    const next =
        last && last[0] === candle[0]
            ? [...series.slice(0, -1), candle]
            : [...series, candle];

    return next.slice(-MAX_CANDLES);
}

/** Variação percentual do primeiro fechamento da janela até o último. */
export function windowChange(series: ChartCandle[]): number {
    if (series.length < 2) {
        return 0;
    }

    const first = series[0][4];

    return first === 0 ? 0 : (series[series.length - 1][4] / first - 1) * 100;
}

/** Endereço do stream combinado de klines de 1 minuto para pares da Binance. */
export function klineStreamUrl(symbols: string[], host: string): string {
    const streams = symbols.map((s) => `${s.toLowerCase()}@kline_1m`).join('/');

    return `wss://${host}/stream?streams=${streams}`;
}
