<?php

namespace App\Agents\Chart;

/**
 * Plain indicator maths over lists of floats / bars. Every method returns null
 * when there is not enough data, never a guess.
 */
final class Indicators
{
    /**
     * @param  list<float>  $values
     */
    public static function ema(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;

        foreach (array_slice($values, $period) as $value) {
            $ema = $value * $k + $ema * (1 - $k);
        }

        return $ema;
    }

    /**
     * Wilder's RSI.
     *
     * @param  list<float>  $closes
     */
    public static function rsi(array $closes, int $period = 14): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        $gain = $loss = 0.0;

        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain += max($change, 0);
            $loss += max(-$change, 0);
        }

        $gain /= $period;
        $loss /= $period;

        for ($i = $period + 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain = ($gain * ($period - 1) + max($change, 0)) / $period;
            $loss = ($loss * ($period - 1) + max(-$change, 0)) / $period;
        }

        if ($loss == 0.0) {
            return $gain == 0.0 ? 50.0 : 100.0;
        }

        return 100 - 100 / (1 + $gain / $loss);
    }

    /**
     * Wilder's average true range.
     *
     * @param  list<array{h: float, l: float, c: float}>  $bars
     */
    public static function atr(array $bars, int $period = 14): ?float
    {
        if (count($bars) <= $period) {
            return null;
        }

        $ranges = [];

        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = $bars[$i - 1]['c'];
            $ranges[] = max($bars[$i]['h'] - $bars[$i]['l'], abs($bars[$i]['h'] - $prevClose), abs($bars[$i]['l'] - $prevClose));
        }

        $atr = array_sum(array_slice($ranges, 0, $period)) / $period;

        foreach (array_slice($ranges, $period) as $range) {
            $atr = ($atr * ($period - 1) + $range) / $period;
        }

        return $atr;
    }

    /**
     * Volume of the last bar divided by the average of the bars before it.
     *
     * @param  list<array{v: float}>  $bars
     */
    public static function relativeVolume(array $bars, int $lookback = 20): ?float
    {
        if (count($bars) < $lookback + 1) {
            return null;
        }

        $last = array_pop($bars);
        $average = array_sum(array_column(array_slice($bars, -$lookback), 'v')) / $lookback;

        return $average > 0 ? $last['v'] / $average : null;
    }
}
