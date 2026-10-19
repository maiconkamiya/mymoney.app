<?php

namespace App\Trading;

/**
 * Raw breakout signals of a candle series, with no entry decision: the box of
 * the last `box` bars before the current one, how compressed it is against
 * the `context` bars before it, relative volume, rising lows, touches of the
 * box high and the distance to it. Works on bars of any size (1 minute for
 * the micro operations, 1 hour for the explorer); nothing after the last bar
 * is read and no model is called.
 *
 * The defaults come from `trading.micro.breakout`; any of them can be passed
 * in `$options` (box, context, touch_zone, volume_recent).
 */
class BreakoutSignals
{
    /**
     * @param  list<array{high: float, low: float, close: float, volume?: float}>  $candles  oldest first
     * @param  array{box?: int, context?: int, touch_zone?: float, volume_recent?: int}  $options
     * @return array{box: list<array{high: float, low: float, close: float, volume?: float}>, bars: int, price: float, high: float, low: float, mid: float, height_pct: float, squeeze: float, context_range_pct: float, broke: bool, gap_pct: float, volume_ratio: float, volume_rise: float, first_low: float, second_low: float, rising_lows: bool, touches: int}|null
     */
    public function measure(array $candles, array $options = []): ?array
    {
        $config = config('trading.micro.breakout');
        $bars = (int) ($options['box'] ?? $config['box_minutes']);
        $context = (int) ($options['context'] ?? $config['context_minutes']);
        $touchZone = (float) ($options['touch_zone'] ?? $config['touch_zone']);
        $recentBars = max(1, (int) ($options['volume_recent'] ?? $config['volume_recent_minutes']));
        $count = count($candles);

        if ($bars < 2 || $context < 1 || $count < $bars + $context + 1) {
            return null;
        }

        $last = $candles[$count - 1];
        $price = (float) $last['close'];

        if ($price <= 0) {
            return null;
        }

        $box = array_slice($candles, $count - 1 - $bars, $bars);
        $before = array_slice($candles, $count - 1 - $bars - $context, $context);
        $high = array_reduce($box, fn (float $carry, array $c) => max($carry, (float) $c['high']), -INF);
        $low = $this->lowest($box);
        $range = fn (array $c) => (float) $c['close'] > 0 ? ((float) $c['high'] - (float) $c['low']) / (float) $c['close'] * 100 : 0.0;
        $boxRange = $this->average(array_map($range, $box));
        $contextRange = $this->average(array_map($range, $before));

        $volume = fn (array $c) => (float) ($c['volume'] ?? 0);
        $boxVolume = $this->average(array_map($volume, $box));
        $recentVolume = $this->average(array_map($volume, array_slice($candles, -$recentBars)));

        $half = intdiv($bars, 2);
        $firstLow = $this->lowest(array_slice($box, 0, $half));
        $secondLow = $this->lowest(array_slice($box, $half));
        $zone = $high - ($high - $low) * $touchZone;

        return [
            'box' => $box,
            'bars' => $bars,
            'price' => $price,
            'high' => $high,
            'low' => $low,
            'mid' => ($high + $low) / 2,
            'height_pct' => ($high - $low) / $price * 100,
            // Average range of a box bar over the average range of a context bar: under 1 the box is narrower.
            'squeeze' => $contextRange > 0 ? $boxRange / $contextRange : INF,
            'context_range_pct' => $contextRange,
            'broke' => $price > $high,
            // Positive below the box high, negative above it.
            'gap_pct' => ($high - $price) / $price * 100,
            // Last bar's volume, and the last `volume_recent` bars' average, over the box average.
            'volume_ratio' => $boxVolume > 0 ? $volume($last) / $boxVolume : 0.0,
            'volume_rise' => $boxVolume > 0 ? $recentVolume / $boxVolume : 0.0,
            'first_low' => $firstLow,
            'second_low' => $secondLow,
            'rising_lows' => $secondLow > $firstLow,
            'touches' => count(array_filter($box, fn (array $c) => (float) $c['high'] >= $zone)),
        ];
    }

    /**
     * @param  list<array{high: float, low: float, close: float, volume?: float}>  $candles
     */
    private function lowest(array $candles): float
    {
        return array_reduce($candles, fn (float $carry, array $c) => min($carry, (float) $c['low']), INF);
    }

    /**
     * @param  list<float>  $values
     */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }
}
