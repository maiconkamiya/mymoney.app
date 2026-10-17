<?php

namespace App\Trading\Micro;

/**
 * Deterministic chart reading on 1-minute candles: volatility (average
 * range), trend (fast and slow moving averages), support and resistance
 * (lowest low and highest high of the recent window). No model is called.
 *
 * Entry needs an uptrend, price near the support or pulled back to the fast
 * average, room below the resistance and a gross target the pair can plausibly
 * reach within the holding time.
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

        // Typical move in the holding time: average 1-minute range times the square root of the minutes.
        $reach = $atrPct * sqrt((float) config('trading.micro.max_hold_minutes'));
        $reachable = $grossMarginPct <= $reach * (float) config('trading.micro.reach_factor');

        // A pullback inside the uptrend also qualifies: price back near the fast average, not stretched above it.
        $stretch = ($price - $fast) / $price * 100;
        $pullback = $uptrend && $stretch <= $atrPct;

        $reasons = [];
        $uptrend || $reasons[] = 'Sem tendência de alta (médias de 9 e 21 minutos).';
        $distance <= $zone || $pullback || $reasons[] = sprintf('Longe do suporte (%.2f%% acima; zona de %.2f%%) e esticado acima da média de 9 minutos (%.2f%%).', $distance, $zone, $stretch);
        $reachable || $reasons[] = sprintf('Alvo bruto de %.2f%% grande demais para este par: ele costuma andar cerca de %.2f%% em %d minutos.', $grossMarginPct, $reach, (int) config('trading.micro.max_hold_minutes'));
        $room >= $grossMarginPct || $reasons[] = sprintf('Resistência perto (%.2f%% de espaço; alvo bruto de %.2f%%).', $room, $grossMarginPct);

        $entry = $reasons === [];

        if ($entry) {
            $reasons[] = sprintf('Tendência de alta, %.2f%% acima do suporte (%s) e %.2f%% de espaço até a resistência.', $distance, $distance <= $zone ? 'perto do suporte' : 'recuo dentro da tendência', $room);
        }

        return new MicroSetup($price, $atrPct, $uptrend, $support, $resistance, $distance, $room, $entry, $reach, $reasons);
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
