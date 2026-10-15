<?php

namespace App\Trading;

use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Contracts\PriceFeed;

class PortfolioValuation
{
    public function __construct(private readonly PriceFeed $prices) {}

    /**
     * Market value of open positions, using the average price when no quote exists.
     */
    public function exposure(Portfolio $portfolio): float
    {
        return $portfolio->openPositions()->with('asset')->get()
            ->sum(fn (Position $position) => $position->qty * ($this->prices->quote($position->asset)->price ?? $position->avg_price));
    }

    public function equity(Portfolio $portfolio): float
    {
        return $portfolio->cash + $this->exposure($portfolio);
    }

    /**
     * Equity at the start of today (first snapshot of the day, or the initial cash).
     */
    public function startOfDayEquity(Portfolio $portfolio): float
    {
        $snapshot = $portfolio->snapshots()
            ->where('taken_at', '>=', now()->startOfDay())
            ->oldest('taken_at')
            ->first();

        return $snapshot->equity ?? $portfolio->initial_cash;
    }

    public function peakEquity(Portfolio $portfolio): float
    {
        return max((float) $portfolio->snapshots()->max('equity'), $portfolio->initial_cash);
    }
}
