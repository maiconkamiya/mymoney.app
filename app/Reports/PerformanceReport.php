<?php

namespace App\Reports;

use App\Enums\Market;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\Portfolio;
use Carbon\CarbonInterface;

/**
 * Equity curve and risk/return numbers of a portfolio, compared with its
 * benchmark (BTC for crypto, IBOV for the B3 unless the portfolio names one).
 */
class PerformanceReport
{
    /**
     * @return array{
     *     benchmark: string,
     *     curve: list<array{date: string, equity: float, benchmark: float|null}>,
     *     return: float,
     *     benchmark_return: float|null,
     *     excess_return: float|null,
     *     max_drawdown: float,
     *     sharpe: float|null,
     * }
     */
    public function build(Portfolio $portfolio): array
    {
        $daily = $portfolio->snapshots()->oldest('taken_at')->get()
            ->groupBy(fn ($snapshot) => $snapshot->taken_at->toDateString())
            ->map(fn ($day) => $day->last());

        $points = [['date' => $portfolio->created_at?->toDateString() ?? $portfolio->starts_at->toDateString(), 'equity' => $portfolio->initial_cash, 'at' => $portfolio->created_at ?? $portfolio->starts_at]];

        foreach ($daily as $date => $snapshot) {
            $points[] = ['date' => (string) $date, 'equity' => $snapshot->equity, 'at' => $snapshot->taken_at];
        }

        $symbol = $this->benchmarkSymbol($portfolio);
        $asset = Asset::query()->where('symbol', $symbol)->first();
        $base = $asset === null ? null : $this->priceAt($asset, $points[0]['at']);

        $curve = array_map(fn (array $point) => [
            'date' => $point['date'],
            'equity' => round($point['equity'], 2),
            'benchmark' => $this->rebased($asset, $base, $point['at'], $portfolio->initial_cash),
        ], $points);

        $equity = array_column($points, 'equity');
        $return = Metrics::totalReturn($equity, $portfolio->initial_cash);
        $last = end($curve);
        $benchmarkReturn = $last['benchmark'] === null ? null : $last['benchmark'] / $portfolio->initial_cash - 1;

        return [
            'benchmark' => $symbol,
            'curve' => $curve,
            'return' => $return,
            'benchmark_return' => $benchmarkReturn,
            'excess_return' => $benchmarkReturn === null ? null : $return - $benchmarkReturn,
            'max_drawdown' => Metrics::maxDrawdown($equity),
            'sharpe' => Metrics::sharpe($equity, $portfolio->market === Market::Crypto ? 365 : 252),
        ];
    }

    private function benchmarkSymbol(Portfolio $portfolio): string
    {
        return $portfolio->benchmark ?? match ($portfolio->market) {
            Market::Crypto => 'BTCUSDT',
            Market::B3 => 'IBOV',
        };
    }

    /**
     * Benchmark value at a date, rebased so it starts at the portfolio's initial cash.
     */
    private function rebased(?Asset $asset, ?float $base, CarbonInterface $at, float $initial): ?float
    {
        if ($asset === null || $base === null || $base <= 0) {
            return null;
        }

        $price = $this->priceAt($asset, $at);

        return $price === null ? null : round($initial * $price / $base, 2);
    }

    /**
     * Last close at or before the date; falls back to the first candle when the date predates the history.
     */
    private function priceAt(Asset $asset, CarbonInterface $at): ?float
    {
        $candle = Candle::query()->where('asset_id', $asset->id)->where('open_at', '<=', $at)->latest('open_at')->first()
            ?? Candle::query()->where('asset_id', $asset->id)->oldest('open_at')->first();

        return $candle === null ? null : (float) $candle->close;
    }
}
