<?php

namespace App\Trading;

use App\Enums\OrderStatus;
use App\Enums\PortfolioStatus;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Contracts\PriceFeed;
use Illuminate\Support\Facades\Cache;

/**
 * Closes open positions that hit their stop or target or whose pair the
 * exchange stopped trading, and finishes portfolios whose period is over.
 */
class PositionExits
{
    /** Exit reason of a position sold because the exchange stopped trading the pair. */
    public const DELISTING = 'delisting';

    public function __construct(
        private readonly PriceFeed $prices,
        private readonly OrderExecutor $executor,
        private readonly PortfolioValuation $valuation,
    ) {}

    /**
     * Sell every position of running portfolios whose last price crossed the stop or the target.
     * Each portfolio waits for the real-time micro process to let go of it.
     *
     * @return int number of positions closed
     */
    public function checkStopsAndTargets(): int
    {
        $closed = 0;

        $portfolios = Portfolio::query()->where('status', PortfolioStatus::Running)->get();

        foreach ($portfolios as $portfolio) {
            $closed += (int) PortfolioLock::run($portfolio->id, fn () => $this->checkPortfolio($portfolio), wait: 20);
        }

        return $closed;
    }

    /**
     * The same check for one portfolio, or for some of its positions. The
     * caller holds the portfolio's lock.
     *
     * @param  list<int>|null  $positionIds
     * @return int number of positions closed
     */
    public function checkPortfolio(Portfolio $portfolio, ?array $positionIds = null): int
    {
        $closed = 0;
        $positions = $portfolio->openPositions()->with(['asset', 'signal'])
            ->when($positionIds !== null, fn ($query) => $query->whereIn('id', $positionIds))
            ->get();

        foreach ($positions as $position) {
            // A resting limit sell (micro operations target) may have executed since the last check.
            $this->executor->refreshResting($position, 'exits');

            if ($position->refresh()->closed_at !== null) {
                $closed++;

                continue;
            }

            if ($position->asset->tradingEnded()) {
                if ($this->liquidateDelisted($position)) {
                    $closed++;
                }

                continue;
            }

            $reason = $this->exitReason($position);

            if ($reason !== null && $this->executor->closePosition($position, $reason, 'exits')?->status === OrderStatus::Filled) {
                $closed++;
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
            PortfolioLock::run($portfolio->id, function () use ($portfolio): void {
                foreach ($portfolio->openPositions()->with(['asset', 'signal'])->get() as $position) {
                    $this->executor->closePosition($position, 'period_end', 'exits');
                }
            }, wait: 20);

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

    /**
     * The exchange stops trading the pair on its end date: the whole position is
     * sold at market (a resting limit sale is cancelled first). When it cannot
     * be sold, for example under the pair's minimum, the reason stays on the
     * portfolio so the owner sees it, and the next minute tries again.
     */
    private function liquidateDelisted(Position $position): bool
    {
        $blocked = null;
        $order = $this->executor->closePosition($position, self::DELISTING, 'exits', $blocked);

        if ($order?->status === OrderStatus::Filled) {
            Cache::forget(self::delistingKey($position));

            return true;
        }

        Cache::put(self::delistingKey($position), $blocked ?? 'Corretora recusou: '.($order->reject_reason ?? 'sem motivo informado'), now()->addDay());

        return false;
    }

    /**
     * Why the position of a pair whose trading ended is still open, or null.
     */
    public static function delistingBlock(Position $position): ?string
    {
        $reason = Cache::get(self::delistingKey($position));

        return is_string($reason) ? $reason : null;
    }

    private static function delistingKey(Position $position): string
    {
        return 'delisting:blocked:'.$position->id;
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

        // With a limit sell resting at the target, the exchange sells there; no market order on top.
        if ($position->target_price !== null && $quote->price >= $position->target_price && $this->executor->restingSells($position)->isEmpty()) {
            return 'target';
        }

        return null;
    }
}
