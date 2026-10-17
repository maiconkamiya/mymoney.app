<?php

namespace App\Trading\Micro;

use App\Enums\Market;
use App\Models\MicroCycle;
use App\Models\Portfolio;

/**
 * What the portfolio page shows about the mode: settings, costs, today's
 * result, the pairs in focus and the latest cycles.
 */
class MicroOverview
{
    public function __construct(
        private readonly MicroTrader $trader,
        private readonly MicroRanker $ranker,
        private readonly MicroCosts $costs,
    ) {}

    /**
     * @return array<string, mixed>|null null for portfolios that cannot use the mode
     */
    public function for(Portfolio $portfolio): ?array
    {
        if ($portfolio->market !== Market::Crypto) {
            return null;
        }

        $gross = $this->costs->grossMarginPct($portfolio->micro_net_margin_pct);
        $today = $portfolio->microCycles()->where('status', 'closed')->where('closed_at', '>=', now()->startOfDay())->get();

        return [
            'enabled' => $portfolio->micro_enabled,
            'order_value' => $portfolio->micro_order_value,
            'net_margin_pct' => $portfolio->micro_net_margin_pct,
            'gross_margin_pct' => $gross,
            'round_trip_cost_pct' => round($this->costs->roundTripPct(), 4),
            'min_order_value' => MicroTrader::minOrderValue($portfolio),
            'daily_target' => $portfolio->micro_daily_target,
            'daily_operations' => $portfolio->micro_daily_operations,
            'daily_loss_limit' => $portfolio->micro_daily_loss_limit,
            'state' => $this->trader->state($portfolio),
            'last_block' => $this->trader->lastBlock($portfolio),
            'today' => [
                'opened' => $this->trader->openedToday($portfolio),
                'cycles' => $today->count(),
                'wins' => $today->where('net_pnl', '>', 0)->count(),
                'net_pnl' => round($today->sum('net_pnl'), 4),
                'fees' => round($today->sum('fees'), 4),
            ],
            'focus' => $this->ranker->rank($portfolio, $gross)->map(fn (array $row) => [
                'symbol' => $row['asset']->symbol,
                'atr_pct' => round($row['setup']->atrPct, 4),
                'entry' => $row['setup']->entry,
                'reason' => $row['setup']->reasons[0] ?? '',
            ])->all(),
            'cycles' => $portfolio->microCycles()->with('asset')->latest('id')->limit(30)->get()->map(fn (MicroCycle $cycle) => [
                'id' => $cycle->id,
                'symbol' => $cycle->asset->symbol,
                'status' => $cycle->status,
                'qty' => $cycle->qty,
                'entry_price' => $cycle->entry_price,
                'target_price' => $cycle->target_price,
                'stop_price' => $cycle->stop_price,
                'exit_price' => $cycle->exit_price,
                'fees' => round($cycle->fees, 6),
                'net_pnl' => $cycle->net_pnl === null ? null : round($cycle->net_pnl, 6),
                'exit_reason' => $cycle->exit_reason,
                'opened_at' => $cycle->opened_at->toIso8601String(),
                'closed_at' => $cycle->closed_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
