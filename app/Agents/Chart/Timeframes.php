<?php

namespace App\Agents\Chart;

/**
 * Aggregates 1-minute bars into larger timeframes. A bar is a list with the
 * keys t (unix seconds of the bucket start), o, h, l, c and v.
 *
 * @phpstan-type Bar array{t: int, o: float, h: float, l: float, c: float, v: float}
 */
final class Timeframes
{
    /** @var array<string, int> minutes per timeframe the analyst looks at */
    public const MINUTES = ['5m' => 5, '15m' => 15, '1h' => 60, '4h' => 240, '1d' => 1440];

    /**
     * @param  list<Bar>  $bars  1-minute bars, oldest first
     * @return list<Bar>
     */
    public static function aggregate(array $bars, int $minutes): array
    {
        $size = $minutes * 60;
        $out = [];

        foreach ($bars as $bar) {
            $start = intdiv($bar['t'], $size) * $size;
            $last = count($out) - 1;

            if ($last >= 0 && $out[$last]['t'] === $start) {
                $out[$last]['h'] = max($out[$last]['h'], $bar['h']);
                $out[$last]['l'] = min($out[$last]['l'], $bar['l']);
                $out[$last]['c'] = $bar['c'];
                $out[$last]['v'] += $bar['v'];

                continue;
            }

            $out[] = ['t' => $start, 'o' => $bar['o'], 'h' => $bar['h'], 'l' => $bar['l'], 'c' => $bar['c'], 'v' => $bar['v']];
        }

        return $out;
    }
}
