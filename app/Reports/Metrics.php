<?php

namespace App\Reports;

/**
 * Pure performance maths over an equity series, so it can be tested without a database.
 */
class Metrics
{
    /**
     * Total return of the series as a fraction (0.05 = +5%).
     *
     * @param  list<float>  $equity
     */
    public static function totalReturn(array $equity, ?float $initial = null): float
    {
        $start = $initial ?? ($equity[0] ?? 0.0);

        if ($start <= 0 || $equity === []) {
            return 0.0;
        }

        return end($equity) / $start - 1;
    }

    /**
     * Largest peak-to-trough fall as a positive fraction.
     *
     * @param  list<float>  $equity
     */
    public static function maxDrawdown(array $equity): float
    {
        $peak = 0.0;
        $worst = 0.0;

        foreach ($equity as $value) {
            $peak = max($peak, $value);

            if ($peak > 0) {
                $worst = max($worst, ($peak - $value) / $peak);
            }
        }

        return $worst;
    }

    /**
     * Period-to-period returns of the series.
     *
     * @param  list<float>  $equity
     * @return list<float>
     */
    public static function returns(array $equity): array
    {
        $returns = [];

        for ($i = 1; $i < count($equity); $i++) {
            if ($equity[$i - 1] > 0) {
                $returns[] = $equity[$i] / $equity[$i - 1] - 1;
            }
        }

        return $returns;
    }

    /**
     * Annualised Sharpe ratio of daily returns (risk-free rate taken as zero);
     * null when there are too few points or no variance.
     *
     * @param  list<float>  $equity
     */
    public static function sharpe(array $equity, int $periodsPerYear): ?float
    {
        $returns = self::returns($equity);
        $n = count($returns);

        if ($n < 2) {
            return null;
        }

        $mean = array_sum($returns) / $n;
        $variance = array_sum(array_map(fn (float $r) => ($r - $mean) ** 2, $returns)) / ($n - 1);

        if ($variance <= 0) {
            return null;
        }

        return $mean / sqrt($variance) * sqrt($periodsPerYear);
    }
}
