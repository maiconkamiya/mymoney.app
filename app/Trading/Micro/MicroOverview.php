<?php

namespace App\Trading\Micro;

use App\Enums\Market;
use App\Models\MicroCycle;
use App\Models\Portfolio;
use Illuminate\Support\Carbon;

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
        $evaluation = $this->ranker->evaluate($portfolio, $gross);
        $lastRun = $this->trader->lastRun($portfolio);
        $today = $portfolio->microCycles()->where('status', 'closed')->where('closed_at', '>=', now()->startOfDay())->get();

        $reachable = $evaluation['ready']->take((int) config('trading.micro.ranking_size'))->max(fn (array $row) => $row['setup']->reachPct) ?? 0;
        $maxMove = $reachable * (float) config('trading.micro.reach_factor');
        $roundTrip = $this->costs->roundTripPct();
        $openCycles = $portfolio->microCycles()->where('status', 'open')->count();
        $canTrade = $portfolio->micro_enabled && $this->trader->state($portfolio)['code'] === 'active';

        return [
            'margin_warning' => $evaluation['ready']->isNotEmpty() && $gross > $maxMove
                ? sprintf(
                    'Com %.2f%% líquido o alvo bruto é %.2f%%, mas os pares em foco andam no máximo cerca de %.2f%% em %d minutos. %s',
                    $portfolio->micro_net_margin_pct,
                    $gross,
                    $maxMove,
                    (int) config('trading.micro.max_hold_minutes'),
                    $maxMove > $roundTrip + 0.05
                        ? sprintf('Tente margem líquida de até %.2f%%.', floor(($maxMove - $roundTrip) * 100) / 100)
                        : 'Nem o custo da ida e volta cabe nesse movimento: escolha pares mais voláteis.',
                )
                : null,
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
            'last_run' => $lastRun,
            // Ligado e sem execução no último 3 minutos: o agendador (mymoney-schedule) não está rodando.
            'scheduler_stale' => $portfolio->micro_enabled && ($lastRun === null || Carbon::parse($lastRun)->lt(now()->subMinutes(3))),
            'today' => [
                'opened' => $this->trader->openedToday($portfolio),
                'cycles' => $today->count(),
                'wins' => $today->where('net_pnl', '>', 0)->count(),
                'net_pnl' => round($today->sum('net_pnl'), 4),
                'fees' => round($today->sum('fees'), 4),
            ],
            'skipped' => $evaluation['skipped'],
            'focus' => $evaluation['ready']->take((int) config('trading.micro.ranking_size'))->map(fn (array $row) => [
                'symbol' => $row['asset']->symbol,
                'atr_pct' => round($row['setup']->atrPct, 4),
                'reach_pct' => round($row['setup']->reachPct * (float) config('trading.micro.reach_factor'), 4),
                'entry' => $row['setup']->entry,
                'reason' => $row['setup']->reasons[0] ?? '',
                'blocked' => $canTrade && $row['setup']->entry ? $this->trader->gate($portfolio, $row['asset'], $row['setup'], $openCycles) : null,
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
