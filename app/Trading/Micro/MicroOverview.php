<?php

namespace App\Trading\Micro;

use App\Enums\Market;
use App\Enums\MicroRule;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\MicroBacktest;
use App\Models\MicroCycle;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Live\BnbFees;
use App\Trading\OrderExecutor;
use App\Trading\PortfolioValuation;
use App\Trading\Risk\RiskGuard;
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
        private readonly OrderExecutor $executor,
        private readonly MicroTargetOdds $odds,
        private readonly RiskGuard $risk,
        private readonly PortfolioValuation $valuation,
        private readonly BnbFees $bnb,
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
        $openCycles = $portfolio->microCycles()->whereIn('status', ['open', 'pending'])->count();
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
            'rule' => $portfolio->micro_rule->value,
            'rules' => array_map(fn (MicroRule $rule) => [
                'value' => $rule->value,
                'label' => $rule->label(),
                'backtest' => $this->backtest($rule),
            ], MicroRule::cases()),
            'entry_mode' => $portfolio->micro_entry_mode === 'market' ? 'market' : 'limit',
            'limit_seconds' => $portfolio->micro_limit_seconds,
            'limit_seconds_range' => [(int) config('trading.micro.limit.min_seconds'), (int) config('trading.micro.limit.max_seconds')],
            'order_value' => $portfolio->micro_order_value,
            'net_margin_pct' => $portfolio->micro_net_margin_pct,
            'gross_margin_pct' => $gross,
            'round_trip_cost_pct' => round($this->costs->roundTripPct(), 4),
            // Carteira real: taxa paga em BNB com desconto e quanto o BNB da conta ainda paga.
            'bnb_fees' => $this->bnb->for($portfolio),
            'min_order_value' => MicroTrader::minOrderValue($portfolio),
            'daily_target_pct' => $portfolio->micro_daily_target_pct,
            'daily_target_value' => ($target = $this->trader->dailyTargetValue($portfolio)) === null ? null : round($target, 2),
            'daily_operations' => $portfolio->micro_daily_operations,
            'wallet_pct' => $portfolio->micro_wallet_pct,
            'wallet_budget' => array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $this->trader->walletBudget($portfolio)),
            // Perda do dia da guarda de risco nesta carteira: a escolhida no card ou a do perfil.
            'daily_loss_pct' => $portfolio->daily_loss_pct,
            'daily_loss' => [
                'limit_pct' => round(100 * $this->risk->dailyLossLimit($portfolio), 4),
                'default_pct' => round(100 * $portfolio->riskProfile->max_daily_loss_pct, 4),
                'max_pct' => RiskGuard::MAX_DAILY_LOSS_PCT,
                'start_equity' => round($this->valuation->startOfDayEquity($portfolio), 2),
                'today_pct' => round(100 * $this->risk->dailyLoss($portfolio), 2),
            ],
            'state' => $this->trader->state($portfolio),
            'last_block' => $this->trader->lastBlock($portfolio),
            'last_run' => $lastRun,
            // O processo micro:watch (serviço mymoney-micro) passou há pouco; senão vale a rodada por minuto.
            'realtime' => MicroTrader::realtimeAlive(),
            // Ligado e sem execução no último 3 minutos: o agendador (mymoney-schedule) não está rodando.
            'scheduler_stale' => $portfolio->micro_enabled && ($lastRun === null || Carbon::parse($lastRun)->lt(now()->subMinutes(3))),
            'today' => [
                'opened' => $this->trader->openedToday($portfolio),
                'cycles' => $today->count(),
                'wins' => $today->where('net_pnl', '>', 0)->count(),
                'net_pnl' => round($today->sum('net_pnl'), 4),
                'fees' => round($today->sum('fees'), 4),
                // Compras limitadas que venceram sem executar hoje (o ciclo delas não fica no histórico).
                'limits_expired' => $portfolio->orders()
                    ->where('reason', 'micro')
                    ->where('side', OrderSide::Buy)
                    ->where('type', OrderType::Limit)
                    ->where('status', OrderStatus::Cancelled)
                    ->whereNull('filled_at')
                    ->where('created_at', '>=', now()->startOfDay())
                    ->count(),
            ],
            'skipped' => $evaluation['skipped'],
            'liquidation' => $this->liquidation($portfolio),
            // Taxa da venda a mercado, para a confirmação do "Liquidar agora" estimar o resultado.
            'sell_fee_rate' => (float) config('trading.costs.crypto.fee_rate'),
            'liquidation_blocked' => $portfolio->isLive() && ! $portfolio->autoExecutesLive()
                ? 'Na carteira real a venda só sai com a execução automática confirmada (CONFIRMO) e sem "Parar tudo".'
                : null,
            'focus' => $evaluation['ready']->take((int) config('trading.micro.ranking_size'))->map(fn (array $row) => [
                'symbol' => $row['asset']->symbol,
                'atr_pct' => round($row['setup']->atrPct, 4),
                'reach_pct' => round($row['setup']->reachPct * (float) config('trading.micro.reach_factor'), 4),
                'entry' => $row['setup']->entry,
                'reason' => $row['setup']->reasons[0] ?? '',
                'checks' => $row['setup']->checks,
                'suggestion' => $row['setup']->entry ? $this->trader->suggestion($portfolio, $row['asset'], $row['setup']) : null,
                'blocked' => $canTrade && $row['setup']->entry ? $this->trader->gate($portfolio, $row['asset'], $row['setup'], $openCycles) : null,
            ])->all(),
            'cycles' => $portfolio->microCycles()->with(['asset', 'position', 'buyOrder'])->latest('id')->limit(30)->get()->map(fn (MicroCycle $cycle) => [
                'id' => $cycle->id,
                'symbol' => $cycle->asset->symbol,
                'status' => $cycle->status,
                'qty' => $cycle->qty,
                'entry_price' => $cycle->entry_price,
                'suggested_price' => $cycle->suggested_price,
                // Como a compra saiu: "limit" (no preço sugerido), "market" ou null nos ciclos de antes da 0.3.85.
                'entry_mode' => $cycle->buyOrder === null ? null : ($cycle->buyOrder->type === OrderType::Limit ? 'limit' : 'market'),
                'expires_at' => $cycle->expires_at?->toIso8601String(),
                'target_price' => $cycle->target_price,
                'stop_price' => $cycle->stop_price,
                'exit_price' => $cycle->exit_price,
                'resting_sell_price' => $cycle->status === 'open' && $cycle->position !== null
                    ? $this->executor->restingSells($cycle->position)->first()?->price
                    : null,
                'sell_failure' => $cycle->status === 'open' && $cycle->position !== null ? $this->trader->sellFailure($cycle->position)['reason'] ?? null : null,
                'fees' => round($cycle->fees, 6),
                'net_pnl' => $cycle->net_pnl === null ? null : round($cycle->net_pnl, 6),
                'exit_reason' => $cycle->exit_reason,
                'opened_at' => $cycle->opened_at->toIso8601String(),
                'closed_at' => $cycle->closed_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * The open positions, each with the "liquidar com lucro" switch, and the
     * marked assets that hold nothing now (still not bought).
     *
     * @return list<array<string, mixed>>
     */
    private function liquidation(Portfolio $portfolio): array
    {
        $markedIds = $portfolio->micro_liquidate_asset_ids ?? [];
        $rows = [];
        foreach ($portfolio->openPositions()->with('asset')->orderBy('id')->get() as $position) {
            $row = $this->trader->liquidation($portfolio, $position);
            $marked = in_array($position->asset_id, $markedIds, true);
            $resting = $this->executor->restingSells($position)->first()?->price;
            $target = $resting !== null ? (float) $resting : ($marked ? $row['sell_from'] : $this->trader->plannedSellPrice($portfolio, $position));
            $rows[$position->asset_id] = [
                'asset_id' => $position->asset_id,
                'symbol' => $position->asset->symbol,
                'marked' => $marked,
                'qty' => round($position->qty, 8),
                'cost' => round($row['cost'], 8),
                'sell_from' => round($row['sell_from'], 8),
                'price' => $row['price'] === null ? null : round($row['price'], 8),
                'resting_sell_price' => $resting,
                'sell_failure' => $this->trader->sellFailure($position),
                'sell_skip' => $resting === null ? $this->trader->sellSkip($portfolio, $position) : null,
                'below_minimum' => $this->trader->belowMinimumNotice($position, $row['price']),
                'odds' => $row['price'] === null ? null : ['target' => round($target, 8)] + $this->odds->estimate($position->asset, $row['price'], $target, $this->minutesLeft($portfolio, $position)),
            ];
        }

        foreach (Asset::query()->whereIn('id', array_diff($markedIds, array_keys($rows)))->orderBy('symbol')->get() as $asset) {
            $rows[$asset->id] = ['asset_id' => $asset->id, 'symbol' => $asset->symbol, 'marked' => true, 'qty' => 0.0, 'cost' => null, 'sell_from' => null, 'price' => null, 'resting_sell_price' => null, 'sell_failure' => null, 'sell_skip' => null, 'below_minimum' => null, 'odds' => null];
        }

        return array_values($rows);
    }

    /**
     * The window the odds look at: what is left of the open cycle's time
     * limit, or the same limit for a position with no cycle.
     */
    private function minutesLeft(Portfolio $portfolio, Position $position): int
    {
        $cycle = $portfolio->microCycles()->where('position_id', $position->id)->where('status', 'open')->first(['opened_at', 'max_hold_minutes']);
        if ($cycle === null) {
            return (int) config('trading.micro.max_hold_minutes');
        }

        return max(1, ($cycle->max_hold_minutes ?? (int) config('trading.micro.max_hold_minutes')) - (int) $cycle->opened_at->diffInMinutes(now()));
    }

    /**
     * The latest saved micro:backtest of a rule, as the card shows it.
     *
     * @return array<string, mixed>|null
     */
    private function backtest(MicroRule $rule): ?array
    {
        $backtest = MicroBacktest::latestFor($rule);

        if ($backtest === null) {
            return null;
        }

        $validation = $backtest->summary['validation'] ?? [];

        return [
            'passed' => $backtest->passed,
            'trades' => (int) ($validation['trades'] ?? 0),
            'mean_pct' => isset($validation['mean_pct']) ? round((float) $validation['mean_pct'], 4) : null,
            'win_rate' => isset($validation['win_rate']) ? round((float) $validation['win_rate'], 4) : null,
            'failures' => array_values($backtest->summary['failures'] ?? []),
            'days' => (int) ($backtest->params['days'] ?? 0),
            'at' => $backtest->created_at->toIso8601String(),
        ];
    }
}
