<?php

namespace App\Agents\Chart;

/**
 * Support and resistance from swing pivots: a bar whose high (low) is the
 * highest (lowest) of `$wing` bars on each side. Pivots that sit within
 * `$tolerance` of each other are merged into one level; the more touches, the
 * stronger the level.
 *
 * @phpstan-import-type Bar from Timeframes
 */
final class Levels
{
    /**
     * @param  list<Bar>  $bars
     * @return list<array{price: float, touches: int, last_t: int}>
     */
    public static function find(array $bars, float $tolerance, int $wing = 3): array
    {
        $pivots = [];
        $count = count($bars);

        for ($i = $wing; $i < $count - $wing; $i++) {
            $isHigh = $isLow = true;

            for ($j = $i - $wing; $j <= $i + $wing; $j++) {
                $isHigh = $isHigh && $bars[$i]['h'] >= $bars[$j]['h'];
                $isLow = $isLow && $bars[$i]['l'] <= $bars[$j]['l'];
            }

            if ($isHigh) {
                $pivots[] = ['price' => $bars[$i]['h'], 't' => $bars[$i]['t']];
            }

            if ($isLow) {
                $pivots[] = ['price' => $bars[$i]['l'], 't' => $bars[$i]['t']];
            }
        }

        usort($pivots, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        $clusters = [];
        $prices = [];
        $lastTouch = 0;
        $previous = null;

        foreach ($pivots as $pivot) {
            if ($previous !== null && $tolerance < $pivot['price'] - $previous) {
                $clusters[] = ['price' => array_sum($prices) / count($prices), 'touches' => count($prices), 'last_t' => $lastTouch];
                $prices = [];
                $lastTouch = 0;
            }

            $prices[] = $pivot['price'];
            $lastTouch = max($lastTouch, $pivot['t']);
            $previous = $pivot['price'];
        }

        if ($prices !== []) {
            $clusters[] = ['price' => array_sum($prices) / count($prices), 'touches' => count($prices), 'last_t' => $lastTouch];
        }

        return $clusters;
    }

    /**
     * The tightest recent band: the last `$bars` bars when their whole range
     * is at most `$maxAtr` ATRs tall.
     *
     * @param  list<Bar>  $bars
     * @return array{low: float, high: float, bars: int}|null
     */
    public static function consolidation(array $bars, float $atr, int $length = 20, float $maxAtr = 4.0): ?array
    {
        if (count($bars) < $length || $atr <= 0) {
            return null;
        }

        $high = -INF;
        $low = INF;

        foreach (array_slice($bars, -$length) as $bar) {
            $high = max($high, $bar['h']);
            $low = min($low, $bar['l']);
        }

        return $high - $low <= $maxAtr * $atr ? ['low' => $low, 'high' => $high, 'bars' => $length] : null;
    }
}
