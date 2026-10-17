<?php

namespace App\Trading\Micro;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Data\OrderRequest;
use App\Trading\Live\LiveLimits;
use App\Trading\OrderExecutor;
use App\Trading\TradingHalt;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the "Micro operações" mode of one portfolio, once a minute: closes the
 * books of finished cycles, forces out the ones held too long, and opens new
 * ones on the most volatile pairs whose chart allows an entry.
 *
 * Exits (target and stop) are the ones every position already has: the
 * regular exits job sells them. Every order goes through the executor, so the
 * risk guard, the live caps and "Parar tudo" apply as for any other order.
 */
class MicroTrader
{
    public const ACTOR = 'micro';

    public function __construct(
        private readonly MicroRanker $ranker,
        private readonly MicroCosts $costs,
        private readonly OrderExecutor $executor,
        private readonly LiveLimits $live,
        private readonly TradingHalt $halt,
    ) {}

    /**
     * @return int number of cycles opened
     */
    public function run(Portfolio $portfolio): int
    {
        if (! $portfolio->micro_enabled) {
            return 0;
        }

        $this->settle($portfolio);
        $this->expire($portfolio);

        if ($this->state($portfolio)['code'] !== 'active') {
            return 0;
        }

        $gross = $this->costs->grossMarginPct($portfolio->micro_net_margin_pct);
        $open = $portfolio->microCycles()->where('status', 'open')->count();
        $opened = 0;

        foreach ($this->ranker->rank($portfolio, $gross) as $row) {
            if ($open >= (int) config('trading.micro.max_open_cycles') || ! $row['setup']->entry) {
                continue;
            }

            if ($this->busy($portfolio, $row['asset'])) {
                continue;
            }

            if ($this->open($portfolio, $row['asset'], $row['setup'], $gross)) {
                $open++;
                $opened++;
            }
        }

        return $opened;
    }

    /**
     * Why the mode is trading, or why it is not.
     *
     * @return array{code: string, label: string}
     */
    public function state(Portfolio $portfolio): array
    {
        if (! $portfolio->micro_enabled) {
            return ['code' => 'off', 'label' => 'Desligado'];
        }

        if (! $portfolio->isRunning()) {
            return ['code' => 'portfolio_stopped', 'label' => 'Carteira fora do período de execução'];
        }

        if ($this->halt->isHalted() || $portfolio->isOrdersBlocked()) {
            return ['code' => 'blocked', 'label' => 'Ordens bloqueadas ("Parar tudo")'];
        }

        if ($portfolio->isLive()) {
            if (! $this->live->enabled()) {
                return ['code' => 'live_off', 'label' => 'Execução real desligada no servidor'];
            }

            if (! $portfolio->autoExecutesLive()) {
                return ['code' => 'live_not_confirmed', 'label' => 'Carteira real sem a execução automática confirmada (CONFIRMO)'];
            }

            if ($this->live->remainingToday($portfolio) < 2 * $portfolio->micro_order_value) {
                return ['code' => 'live_cap', 'label' => 'Teto diário de ordens reais esgotado'];
            }
        }

        $pnl = $this->pnlToday($portfolio);

        if ($portfolio->micro_daily_target !== null && $pnl >= $portfolio->micro_daily_target) {
            return ['code' => 'target_hit', 'label' => 'Meta do dia batida'];
        }

        if ($portfolio->micro_daily_loss_limit !== null && $pnl <= -$portfolio->micro_daily_loss_limit) {
            return ['code' => 'loss_limit', 'label' => 'Perda máxima do dia atingida'];
        }

        return ['code' => 'active', 'label' => 'Operando'];
    }

    /**
     * Net profit of the cycles closed today.
     */
    public function pnlToday(Portfolio $portfolio): float
    {
        return (float) $portfolio->microCycles()
            ->where('status', 'closed')
            ->where('closed_at', '>=', now()->startOfDay())
            ->sum('net_pnl');
    }

    /**
     * Book the cycles whose position was sold (target, stop, forced exit or "Parar tudo").
     */
    public function settle(Portfolio $portfolio): void
    {
        $cycles = $portfolio->microCycles()->where('status', 'open')->with('position')->get();

        foreach ($cycles as $cycle) {
            $position = $cycle->position;

            if ($position === null || $position->closed_at === null) {
                continue;
            }

            $sell = Order::query()
                ->where('portfolio_id', $portfolio->id)
                ->where('asset_id', $cycle->asset_id)
                ->where('side', OrderSide::Sell)
                ->where('status', OrderStatus::Filled)
                ->where('filled_at', '>=', $cycle->opened_at)
                ->latest('filled_at')
                ->first();

            $exitPrice = $sell?->price ?? $position->avg_price;
            $fees = $cycle->fees + ($sell?->fees ?? 0);
            $net = $cycle->qty * ($exitPrice - $cycle->entry_price) - $fees;

            $cycle->update([
                'status' => 'closed',
                'exit_price' => $exitPrice,
                'fees' => $fees,
                'net_pnl' => $net,
                'exit_reason' => $position->exit_reason ?? 'sold',
                'closed_at' => $position->closed_at,
            ]);

            AuditLog::record(self::ACTOR, 'micro.closed', $cycle, [
                'portfolio_id' => $portfolio->id,
                'exit_reason' => $cycle->exit_reason,
                'net_pnl' => round($net, 8),
            ]);
        }
    }

    /**
     * Sell what stayed open longer than the limit: a cycle is meant to be quick.
     */
    private function expire(Portfolio $portfolio): void
    {
        $cycles = $portfolio->microCycles()
            ->where('status', 'open')
            ->where('opened_at', '<=', now()->subMinutes((int) config('trading.micro.max_hold_minutes')))
            ->with('position.asset')
            ->get();

        foreach ($cycles as $cycle) {
            if ($cycle->position !== null && $cycle->position->closed_at === null) {
                $this->executor->closePosition($cycle->position, 'micro_time', self::ACTOR);
            }
        }

        $this->settle($portfolio);
    }

    /**
     * One cycle per pair at a time, and a pause after a stop on that pair.
     */
    private function busy(Portfolio $portfolio, Asset $asset): bool
    {
        if ($portfolio->openPositions()->where('asset_id', $asset->id)->exists()) {
            return true;
        }

        return $portfolio->microCycles()
            ->where('asset_id', $asset->id)
            ->where('status', 'closed')
            ->where('exit_reason', 'stop')
            ->where('closed_at', '>=', now()->subMinutes((int) config('trading.micro.cooldown_minutes')))
            ->exists();
    }

    private function open(Portfolio $portfolio, Asset $asset, MicroSetup $setup, float $grossPct): bool
    {
        $qty = $portfolio->micro_order_value / $setup->price;
        $stop = $setup->price * (1 - $grossPct / 100 * (float) config('trading.micro.stop_ratio'));

        $outcome = $this->executor->placeDirect($portfolio, new OrderRequest(
            asset: $asset,
            side: OrderSide::Buy,
            qty: $qty,
            stopPrice: $stop,
            clientId: 'micro-'.$portfolio->id.'-'.$asset->id.'-'.now()->format('YmdHi'),
        ), self::ACTOR, 'micro');

        if (is_string($outcome) || $outcome->status !== OrderStatus::Filled) {
            Cache::put($this->blockKey($portfolio), $asset->symbol.': '.(is_string($outcome) ? $outcome : 'Corretora recusou: '.$outcome->reject_reason), now()->addHour());

            return false;
        }

        /** @var Position|null $position */
        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->latest('id')->first();

        if ($position === null) {
            return false;
        }

        $entry = $outcome->price ?? $setup->price;
        $target = $entry * (1 + $grossPct / 100);
        $stop = $entry * (1 - $grossPct / 100 * (float) config('trading.micro.stop_ratio'));

        $position->update(['target_price' => $target, 'stop_price' => $stop]);

        $cycle = $portfolio->microCycles()->create([
            'asset_id' => $asset->id,
            'position_id' => $position->id,
            'status' => 'open',
            'qty' => $position->qty,
            'entry_price' => $entry,
            'target_price' => $target,
            'stop_price' => $stop,
            'fees' => $outcome->fees,
            'gross_margin_pct' => $grossPct,
            'analysis' => $setup->toArray(),
            'opened_at' => now(),
        ]);

        AuditLog::record(self::ACTOR, 'micro.opened', $cycle, ['portfolio_id' => $portfolio->id, 'symbol' => $asset->symbol, 'order_id' => $outcome->id]);

        return true;
    }

    public function lastBlock(Portfolio $portfolio): ?string
    {
        return Cache::get($this->blockKey($portfolio));
    }

    private function blockKey(Portfolio $portfolio): string
    {
        return 'micro:last-block:'.$portfolio->id;
    }
}
