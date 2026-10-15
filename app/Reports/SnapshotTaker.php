<?php

namespace App\Reports;

use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Trading\PortfolioValuation;

/**
 * Records the daily equity point that feeds the equity curve and the report.
 */
class SnapshotTaker
{
    public function __construct(private readonly PortfolioValuation $valuation) {}

    public function take(Portfolio $portfolio): PortfolioSnapshot
    {
        $exposure = $this->valuation->exposure($portfolio);
        $equity = $portfolio->cash + $exposure;
        $peak = max($this->valuation->peakEquity($portfolio), $equity);

        return $portfolio->snapshots()->create([
            'taken_at' => now(),
            'equity' => $equity,
            'cash' => $portfolio->cash,
            'exposure' => $exposure,
            'drawdown' => $peak > 0 ? round(($peak - $equity) / $peak, 5) : 0,
        ]);
    }
}
