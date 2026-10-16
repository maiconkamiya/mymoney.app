import { describe, expect, it } from 'vitest';
import { applyKline, klineStreamUrl, windowChange } from './liveCharts';
import type { ChartCandle } from '@/types';

const k = (t: number, c: string) => ({ t, o: c, h: c, l: c, c, v: '1' });

describe('liveCharts', () => {
    it('atualiza o candle aberto e acrescenta o novo', () => {
        let s: ChartCandle[] = [[60000, 1, 1, 1, 1, 1]];
        s = applyKline(s, k(60000, '2'));
        expect(s).toHaveLength(1);
        expect(s[0][4]).toBe(2);
        s = applyKline(s, k(120000, '3'));
        expect(s).toHaveLength(2);
        expect(s[1][4]).toBe(3);
    });

    it('ignora candle mais antigo e limita a janela', () => {
        const s: ChartCandle[] = [[120000, 1, 1, 1, 1, 1]];
        expect(applyKline(s, k(60000, '9'))).toBe(s);
        let big: ChartCandle[] = [];
        for (let i = 0; i < 120; i++) big = applyKline(big, k(i * 60000, '1'));
        expect(big).toHaveLength(90);
    });

    it('calcula a variação da janela', () => {
        expect(
            windowChange([
                [0, 1, 1, 1, 100, 1],
                [1, 1, 1, 1, 110, 1],
            ]),
        ).toBeCloseTo(10);
        expect(windowChange([])).toBe(0);
    });

    it('monta o endereço do stream', () => {
        expect(klineStreamUrl(['BTCUSDT', 'ETHUSDT'], 'h.io')).toBe(
            'wss://h.io/stream?streams=btcusdt@kline_1m/ethusdt@kline_1m',
        );
    });
});
