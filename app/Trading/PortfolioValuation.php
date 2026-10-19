<?php

namespace App\Trading;

use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
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
     * Equity at the start of today: the first snapshot of the day, else the last one before it
     * (the daily snapshot is taken at 23:55), else the initial cash. A real portfolio's initial cash
     * is only the base of the result (the owner can edit it): with no snapshot yet, the equity seen
     * now is recorded and becomes the reference.
     */
    public function startOfDayEquity(Portfolio $portfolio): float
    {
        $snapshot = $portfolio->snapshots()
            ->where('taken_at', '>=', now()->startOfDay())
            ->oldest('taken_at')
            ->first()
            ?? $portfolio->snapshots()
                ->where('taken_at', '<', now()->startOfDay())
                ->latest('taken_at')
                ->first();

        if ($snapshot === null && $portfolio->isLive()) {
            $equity = $this->equity($portfolio);

            return $equity > 0 ? $this->anchor($portfolio)->equity : $equity;
        }

        return $snapshot->equity ?? $portfolio->initial_cash;
    }

    /**
     * Highest equity seen. A real portfolio's initial cash is only the base of the result (the
     * owner can edit it), so its peak comes from the snapshots alone, or from the equity seen now.
     */
    public function peakEquity(Portfolio $portfolio): float
    {
        $peak = $portfolio->snapshots()->max('equity');

        if ($portfolio->isLive()) {
            return $peak !== null ? (float) $peak : $this->equity($portfolio);
        }

        return max((float) $peak, $portfolio->initial_cash);
    }

    /**
     * First equity point of a real portfolio that has none, so the day's loss is measured from here.
     */
    private function anchor(Portfolio $portfolio): PortfolioSnapshot
    {
        $exposure = $this->exposure($portfolio);

        return $portfolio->snapshots()->create([
            'taken_at' => now(),
            'equity' => $portfolio->cash + $exposure,
            'cash' => $portfolio->cash,
            'exposure' => $exposure,
            'drawdown' => 0,
        ]);
    }
}
