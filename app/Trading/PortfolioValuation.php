<?php

namespace App\Trading;

use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Trading\Contracts\PriceFeed;
use Carbon\CarbonInterface;

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
     * now is recorded and becomes the reference. In a simulated portfolio the deposits made after
     * the snapshot are added, so a deposit never reads as a gain today nor as a loss tomorrow.
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

        if ($snapshot !== null && ! $portfolio->isLive()) {
            return $snapshot->equity + $this->depositedAfter($portfolio, $snapshot->taken_at);
        }

        return $snapshot->equity ?? $portfolio->initial_cash;
    }

    /**
     * Sum of the simulated portfolio's deposits made after the given moment.
     */
    public function depositedAfter(Portfolio $portfolio, CarbonInterface $at): float
    {
        return (float) $portfolio->deposits()->where('deposited_at', '>', $at)->sum('amount');
    }

    /**
     * Highest equity seen. A real portfolio's initial cash is only the base of the result (the
     * owner can edit it), so its peak comes from the snapshots alone, or from the equity seen now.
     * In a simulated portfolio each snapshot counts with the deposits made after it (the initial
     * cash already includes them).
     *
     * Once the owner resets the peak ("Zerar maior saldo", #222), the equity of that moment is the
     * new starting point and only snapshots taken after it count.
     */
    public function peakEquity(Portfolio $portfolio): float
    {
        $since = $portfolio->peak_reset_at;
        $base = $since === null ? null : (float) $portfolio->peak_reset_equity;
        $snapshots = fn () => $portfolio->snapshots()->when($since !== null, fn ($query) => $query->where('taken_at', '>=', $since));
        $peak = $snapshots()->max('equity');
        $peak = $base === null ? $peak : max((float) $peak, $base);

        if ($portfolio->isLive()) {
            return $peak !== null ? (float) $peak : $this->equity($portfolio);
        }

        $deposits = $portfolio->deposits()
            ->when($since !== null, fn ($query) => $query->where('deposited_at', '>', $since))
            ->orderBy('deposited_at')->get(['amount', 'deposited_at']);
        // The initial cash holds every deposit: after a reset it no longer floors the peak.
        $floor = $since === null ? $portfolio->initial_cash : 0.0;

        if ($deposits->isEmpty()) {
            return max((float) $peak, $floor);
        }

        $peak = 0.0;
        $from = null;
        $pending = (float) $deposits->sum('amount');

        // Snapshots between two deposits share the same adjustment: the deposits still to come.
        foreach ([...$deposits->all(), null] as $deposit) {
            $max = $snapshots()
                ->when($from !== null, fn ($query) => $query->where('taken_at', '>=', $from))
                ->when($deposit !== null, fn ($query) => $query->where('taken_at', '<', $deposit->deposited_at))
                ->max('equity');

            // The reset point is the first equity of the first stretch.
            $max = $from === null && $base !== null ? max((float) $max, $base) : $max;

            if ($max !== null) {
                $peak = max($peak, (float) $max + $pending);
            }

            if ($deposit !== null) {
                $from = $deposit->deposited_at;
                $pending -= $deposit->amount;
            }
        }

        return max($peak, $floor);
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
