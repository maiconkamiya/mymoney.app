<?php

namespace App\Trading\Micro;

/**
 * Deterministic chart reading on 1-minute candles: volatility (average
 * range), trend (fast and slow moving averages), support and resistance
 * (lowest low and highest high of the recent window). No model is called.
 *
 * Entry needs an uptrend, price sitting near the support and enough room
 * below the resistance to reach the gross target.
 */
class MicroAnalysis
{
    /**
     * @param  list<array{high: float, low: float, close: float}>  $candles  oldest first
     */
    public function read(array $candles, float $grossMarginPct): ?MicroSetup
    {
        $atrWindow = (int) config('trading.micro.atr_window');

        if (count($candles) < max(40, $atrWindow)) {
            return null;
        }

        $closes = array_column($candles, 'close');
        $price = (float) end($closes);

        if ($price <= 0) {
            return null;
        }

        $recent = array_slice($candles, -$atrWindow);
        $atrPct = array_sum(array_map(fn (array $c) => ($c['high'] - $c['low']) / $c['close'] * 100, $recent)) / count($recent);

        $fast = $this->ema($closes, 9);
        $slow = $this->ema($closes, 21);
        $slowBefore = $this->ema(array_slice($closes, 0, -5), 21);
        $uptrend = $fast > $slow && $price >= $slow && $slow > $slowBefore;

        $support = array_reduce(array_column(array_slice($candles, -30), 'low'), fn (float $carry, float $low) => min($carry, $low), $price);
        $resistance = array_reduce(array_column(array_slice($candles, -60), 'high'), fn (float $carry, float $high) => max($carry, $high), $price);
        $distance = ($price - $support) / $price * 100;
        $room = ($resistance - $price) / $price * 100;
        $zone = $grossMarginPct * (float) config('trading.micro.support_zone');

        $reasons = [];
        $uptrend || $reasons[] = 'Sem tendência de alta (médias de 9 e 21 minutos).';
        $distance <= $zone || $reasons[] = sprintf('Longe do suporte (%.2f%% acima; zona de %.2f%%).', $distance, $zone);
        $room >= $grossMarginPct || $reasons[] = sprintf('Resistência perto (%.2f%% de espaço; alvo bruto de %.2f%%).', $room, $grossMarginPct);

        $entry = $reasons === [];

        if ($entry) {
            $reasons[] = sprintf('Tendência de alta, %.2f%% acima do suporte e %.2f%% de espaço até a resistência.', $distance, $room);
        }

        return new MicroSetup($price, $atrPct, $uptrend, $support, $resistance, $distance, $room, $entry, $reasons);
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
