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
            $window = array_slice($bars, $i - $wing, $wing * 2 + 1);
            $highs = array_column($window, 'h');
            $lows = array_column($window, 'l');

            if ($bars[$i]['h'] >= max($highs)) {
                $pivots[] = ['price' => $bars[$i]['h'], 't' => $bars[$i]['t']];
            }

            if ($bars[$i]['l'] <= min($lows)) {
                $pivots[] = ['price' => $bars[$i]['l'], 't' => $bars[$i]['t']];
            }
        }

        usort($pivots, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        $clusters = [];

        foreach ($pivots as $pivot) {
            $last = count($clusters) - 1;

            if ($last >= 0 && $pivot['price'] - end($clusters[$last]['prices']) <= $tolerance) {
                $clusters[$last]['prices'][] = $pivot['price'];
                $clusters[$last]['last_t'] = max($clusters[$last]['last_t'], $pivot['t']);

                continue;
            }

            $clusters[] = ['prices' => [$pivot['price']], 'last_t' => $pivot['t']];
        }

        return array_map(fn (array $cluster) => [
            'price' => array_sum($cluster['prices']) / count($cluster['prices']),
            'touches' => count($cluster['prices']),
            'last_t' => $cluster['last_t'],
        ], $clusters);
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

        $recent = array_slice($bars, -$length);
        $high = max(array_column($recent, 'h'));
        $low = min(array_column($recent, 'l'));

        return $high - $low <= $maxAtr * $atr ? ['low' => $low, 'high' => $high, 'bars' => $length] : null;
    }
}
