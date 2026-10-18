<?php

namespace App\Trading\Micro;

/**
 * The "Tendência com recuo" rule, read from 1-minute candles grouped into
 * 5 min, 15 min and 1 h bars (counted back from the last closed minute, so
 * nothing after it is seen). No model is called.
 *
 * Entry needs the 1 h EMA21 rising with the price above it, the 15 min EMA9
 * above the 15 min EMA21, the price back within half a 15 min ATR of that
 * EMA21 and the last 5 min bar closing above the one before. The stop sits
 * 1.5 ATR of 15 min below; the target pays 1.5 times that risk and never less
 * than 4 times the round-trip cost, so the cost is a small share of the gain.
 */
class MicroTrendAnalysis
{
    private const HOURLY_BARS = 22;

    /**
     * @param  list<array{high: float, low: float, close: float}>  $candles  oldest first
     */
    public function read(array $candles, float $roundTripPct): ?MicroSetup
    {
        if (count($candles) < self::HOURLY_BARS * 60) {
            return null;
        }

        $config = config('trading.micro.trend');
        $price = (float) $candles[count($candles) - 1]['close'];

        if ($price <= 0) {
            return null;
        }

        $hourly = array_column($this->bars($candles, 60), 'close');
        $quarter = $this->bars($candles, 15);
        $quarterCloses = array_column($quarter, 'close');
        $five = array_column($this->bars(array_slice($candles, -10), 5), 'close');

        $ema21h = $this->ema($hourly, 21);
        $ema21hBefore = $this->ema(array_slice($hourly, 0, -3), 21);
        $hourTrend = $price > $ema21h && $ema21h > $ema21hBefore;

        $ema9q = $this->ema($quarterCloses, 9);
        $ema21q = $this->ema($quarterCloses, 21);
        $quarterTrend = $ema9q > $ema21q;

        $recentQuarter = array_slice($quarter, -14);
        $atr15 = array_sum(array_map(fn (array $bar) => $bar['high'] - $bar['low'], $recentQuarter)) / count($recentQuarter);
        $atr15Pct = $atr15 / $price * 100;
        $offset = $atr15 > 0 ? ($price - $ema21q) / $atr15 : INF;
        $pullback = abs($offset) <= (float) $config['pullback_atr'];

        $turn = count($five) === 2 && $five[1] > $five[0];

        $stopPct = (float) $config['stop_atr'] * $atr15Pct;
        $targetPct = max((float) $config['reward_risk'] * $stopPct, (float) $config['min_target_costs'] * $roundTripPct);
        $hold = (int) $config['max_hold_minutes'];

        // Average 1-minute range, the same volatility measure the screen shows for every rule.
        $recent = array_slice($candles, -(int) config('trading.micro.atr_window'));
        $atrPct = array_sum(array_map(fn (array $c) => ($c['high'] - $c['low']) / $c['close'] * 100, $recent)) / count($recent);

        $checks = [
            ['key' => 'trend', 'label' => 'Tendência de 1 h', 'ok' => $hourTrend, 'detail' => $hourTrend ? 'Preço acima da média de 21 horas, e ela subindo.' : 'Preço abaixo da média de 21 horas ou ela sem subir.'],
            ['key' => 'support', 'label' => 'Recuo até a média de 15 min', 'ok' => $pullback, 'detail' => sprintf('%.2f ATR de 15 min da média de 21 (máximo %.1f).', $offset, (float) $config['pullback_atr'])],
            ['key' => 'reach', 'label' => 'Tendência de 15 min', 'ok' => $quarterTrend, 'detail' => $quarterTrend ? 'Média de 9 acima da de 21 em 15 min.' : 'Média de 9 abaixo da de 21 em 15 min.'],
            ['key' => 'room', 'label' => 'Virada em 5 min', 'ok' => $turn, 'detail' => $turn ? 'Última vela de 5 min fechou acima da anterior.' : 'Última vela de 5 min não fechou acima da anterior.'],
        ];

        $reasons = [];
        $hourTrend || $reasons[] = 'Sem tendência de alta em 1 h (média de 21 horas).';
        $quarterTrend || $reasons[] = 'Sem tendência de alta em 15 min (médias de 9 e 21).';
        $pullback || $reasons[] = sprintf('Longe da média de 21 de 15 min (%.2f ATR; recuo aceito até %.1f).', $offset, (float) $config['pullback_atr']);
        $turn || $reasons[] = 'Esperando a vela de 5 min virar para cima.';

        $entry = $reasons === [];

        if ($entry) {
            $reasons[] = sprintf('Tendência de alta em 1 h e 15 min, recuo até a média de 15 min e virada em 5 min. Alvo %.2f%%, stop %.2f%%, até %d h.', $targetPct, $stopPct, intdiv($hold, 60));
        }

        return new MicroSetup(
            price: $price,
            atrPct: $atrPct,
            uptrend: $hourTrend && $quarterTrend,
            support: $ema21q,
            resistance: $price * (1 + $targetPct / 100),
            distanceToSupportPct: ($price - $ema21q) / $price * 100,
            roomToResistancePct: $targetPct,
            entry: $entry,
            reachPct: $atrPct * sqrt($hold),
            reasons: $reasons,
            checks: $checks,
            targetPct: $targetPct,
            stopPct: $stopPct,
            maxHoldMinutes: $hold,
        );
    }

    /**
     * Groups 1-minute candles into bars of `$size` minutes, counted back from the last one.
     *
     * @param  list<array{high: float, low: float, close: float}>  $candles
     * @return list<array{high: float, low: float, close: float}>
     */
    private function bars(array $candles, int $size): array
    {
        $count = intdiv(count($candles), $size);
        $bars = [];

        for ($i = count($candles) - $count * $size; $i < count($candles); $i += $size) {
            $high = -INF;
            $low = INF;

            for ($j = $i; $j < $i + $size; $j++) {
                $high = max($high, (float) $candles[$j]['high']);
                $low = min($low, (float) $candles[$j]['low']);
            }

            $bars[] = ['high' => $high, 'low' => $low, 'close' => (float) $candles[$i + $size - 1]['close']];
        }

        return $bars;
    }

    /**
     * @param  list<float>  $values
     */
    private function ema(array $values, int $period): float
    {
        $k = 2 / ($period + 1);
        $ema = $values[0];

        foreach ($values as $value) {
            $ema = $value * $k + $ema * (1 - $k);
        }

        return $ema;
    }
}
