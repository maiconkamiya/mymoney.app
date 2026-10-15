<?php

namespace App\Trading;

use App\Enums\OrderStatus;
use App\Enums\PortfolioStatus;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Contracts\PriceFeed;

/**
 * Closes open positions that hit their stop or target, and finishes
 * portfolios whose period is over.
 */
class PositionExits
{
    public function __construct(
        private readonly PriceFeed $prices,
        private readonly OrderExecutor $executor,
        private readonly PortfolioValuation $valuation,
    ) {}

    /**
     * Sell every position of running portfolios whose last price crossed the stop or the target.
     *
     * @return int number of positions closed
     */
    public function checkStopsAndTargets(): int
    {
        $closed = 0;

        $portfolios = Portfolio::query()->where('status', PortfolioStatus::Running)->get();

        foreach ($portfolios as $portfolio) {
            foreach ($portfolio->openPositions()->with(['asset', 'signal'])->get() as $position) {
                $reason = $this->exitReason($position);

                if ($reason !== null && $this->executor->closePosition($position, $reason, 'exits')?->status === OrderStatus::Filled) {
                    $closed++;
                }
            }
        }

        return $closed;
    }

    /**
     * Liquidate and finish portfolios past `ends_at`. A portfolio stays running
     * (and is retried on the next call) while a position cannot be sold, for
     * example because the B3 session is closed.
     *
     * @return int number of portfolios finished
     */
    public function finishExpired(): int
    {
        $finished = 0;

        $portfolios = Portfolio::query()
            ->where('status', PortfolioStatus::Running)
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($portfolios as $portfolio) {
            foreach ($portfolio->openPositions()->with(['asset', 'signal'])->get() as $position) {
                $this->executor->closePosition($position, 'period_end', 'exits');
            }

            if ($portfolio->openPositions()->exists()) {
                continue;
            }

            $equity = $this->valuation->equity($portfolio);

            $portfolio->snapshots()->create([
                'taken_at' => now(),
                'equity' => $equity,
                'cash' => $portfolio->cash,
                'exposure' => 0,
                'drawdown' => max(0, 1 - $equity / max($this->valuation->peakEquity($portfolio), 0.00000001)),
            ]);

            $portfolio->update(['status' => PortfolioStatus::Finished]);
            AuditLog::record('exits', 'portfolio.finished', $portfolio, [
                'initial_cash' => $portfolio->initial_cash,
                'final_equity' => $equity,
            ]);
            $finished++;
        }

        return $finished;
    }

    private function exitReason(Position $position): ?string
    {
        $quote = $this->prices->quote($position->asset);

        if ($quote === null) {
            return null;
        }

        if ($position->stop_price !== null && $quote->price <= $position->stop_price) {
            return 'stop';
        }

        if ($position->target_price !== null && $quote->price >= $position->target_price) {
            return 'target';
        }

        return null;
    }
}
