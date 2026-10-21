<?php

namespace App\Trading\Micro;

/**
 * Deterministic chart reading on 1-minute candles: volatility (average
 * range), trend (fast and slow moving averages), support and resistance
 * (lowest low and highest high of the recent window). No model is called.
 *
 * With a timeframe above 1 minute (#218) the 1-minute candles are grouped into
 * bars of that many minutes, counted back from the last one, and every window
 * (averages, range, support, resistance) counts bars instead of minutes. The
 * setup's `atrPct` stays the average 1-minute range, so the suggested buy price
 * and the pairs in focus read the same as in the 1-minute rule.
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
    public function read(array $candles, float $grossMarginPct, int $timeframe = 1): ?MicroSetup
    {
        $atrWindow = (int) config('trading.micro.atr_window');
        $minuteCandles = $candles;
        $timeframe = max(1, $timeframe);

        if ($timeframe > 1) {
            $candles = $this->bars($candles, $timeframe);
        }

        if (count($candles) < max(40, $atrWindow)) {
            return null;
        }

        $closes = array_column($candles, 'close');
        $price = (float) end($closes);

        if ($price <= 0) {
            return null;
        }

        $range = fn (array $rows) => array_sum(array_map(fn (array $c) => ($c['high'] - $c['low']) / $c['close'] * 100, $rows)) / count($rows);
        $barAtrPct = $range(array_slice($candles, -$atrWindow));
        $atrPct = $timeframe > 1 ? $range(array_slice($minuteCandles, -$atrWindow)) : $barAtrPct;
        $unit = $timeframe > 1 ? "velas de {$timeframe} minutos" : 'minutos';
        $hold = (int) config('trading.micro.max_hold_minutes');

        $fast = $this->ema($closes, 9);
        $slow = $this->ema($closes, 21);
        $slowBefore = $this->ema(array_slice($closes, 0, -5), 21);
        $uptrend = $fast > $slow && $price >= $slow && $slow > $slowBefore;

        $support = array_reduce(array_column(array_slice($candles, -30), 'low'), fn (float $carry, float $low) => min($carry, $low), $price);
        $resistance = array_reduce(array_column(array_slice($candles, -60), 'high'), fn (float $carry, float $high) => max($carry, $high), $price);
        $distance = ($price - $support) / $price * 100;
        $room = ($resistance - $price) / $price * 100;
        $zone = $grossMarginPct * (float) config('trading.micro.support_zone');

        // Typical move in the holding time: average range of one bar times the square root of the bars in it.
        $reach = $barAtrPct * sqrt($hold / $timeframe);
        $reachable = $grossMarginPct <= $reach * (float) config('trading.micro.reach_factor');

        // A pullback inside the uptrend also qualifies: price back near the fast average, not stretched above it.
        $stretch = ($price - $fast) / $price * 100;
        $pullback = $uptrend && $stretch <= $barAtrPct;

        $reasons = [];
        $uptrend || $reasons[] = "Sem tendência de alta (médias de 9 e 21 {$unit}).";
        $distance <= $zone || $pullback || $reasons[] = sprintf('Longe do suporte (%.2f%% acima; zona de %.2f%%) e esticado acima da média de 9 %s (%.2f%%).', $distance, $zone, $unit, $stretch);
        $reachable || $reasons[] = sprintf('Alvo bruto de %.2f%% grande demais para este par: ele costuma andar cerca de %.2f%% em %d minutos.', $grossMarginPct, $reach, $hold);
        $room >= $grossMarginPct || $reasons[] = sprintf('Resistência perto (%.2f%% de espaço; alvo bruto de %.2f%%).', $room, $grossMarginPct);

        $entry = $reasons === [];

        // The same four conditions, one by one, so the screen can show which pass and which block.
        $checks = [
            ['key' => 'trend', 'label' => 'Tendência de alta', 'ok' => $uptrend, 'detail' => $uptrend ? "Média de 9 {$unit} acima da de 21, e a de 21 subindo." : "Médias de 9 e 21 {$unit} sem alta."],
            ['key' => 'support', 'label' => $distance <= $zone || ! $pullback ? 'Perto do suporte' : 'Recuo na tendência', 'ok' => $distance <= $zone || $pullback, 'detail' => sprintf('%.2f%% acima do suporte (zona de %.2f%%); %.2f%% da média de 9 %s.', $distance, $zone, $stretch, $unit)],
            ['key' => 'reach', 'label' => 'Alvo alcançável', 'ok' => $reachable, 'detail' => sprintf('Alvo bruto de %.2f%%; o par costuma andar %.2f%% em %d minutos.', $grossMarginPct, $reach, $hold)],
            ['key' => 'room', 'label' => 'Espaço até a resistência', 'ok' => $room >= $grossMarginPct, 'detail' => sprintf('%.2f%% de espaço para um alvo bruto de %.2f%%.', $room, $grossMarginPct)],
        ];

        if ($entry) {
            $reasons[] = sprintf('Tendência de alta, %.2f%% acima do suporte (%s) e %.2f%% de espaço até a resistência.', $distance, $distance <= $zone ? 'perto do suporte' : 'recuo dentro da tendência', $room);
        }

        return new MicroSetup($price, $atrPct, $uptrend, $support, $resistance, $distance, $room, $entry, $reach, $reasons, $checks);
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
