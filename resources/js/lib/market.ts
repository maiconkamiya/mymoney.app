import type { Candle } from '@/types';

/**
 * Applies a live candle to an ascending series: replaces the candle with the
 * same open time, or appends a newer one. Older candles are ignored.
 */
export function mergeCandle(
    candles: Candle[],
    incoming: Candle,
    limit = 500,
): Candle[] {
    const last = candles.at(-1);

    if (last === undefined || incoming.time > last.time) {
        return [...candles, incoming].slice(-limit);
    }

    const index = candles.findIndex((candle) => candle.time === incoming.time);

    if (index === -1) {
        return candles;
    }

    const next = [...candles];
    next[index] = incoming;

    return next;
}

export function priceFormat(value: number, tickSize: string): string {
    const decimals = Math.max(
        0,
        tickSize.replace(/0+$/, '').split('.')[1]?.length ?? 0,
    );

    return value.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}
