<?php

namespace App\Http\Controllers;

use App\Agents\AgentActivity;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Http\Requests\StorePortfolioRequest;
use App\Http\Requests\UpdatePortfolioRequest;
use App\Models\AgentScore;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Reports\PerformanceReport;
use App\Reports\TaxReport;
use App\Trading\Live\LiveLimits;
use App\Trading\PortfolioCharts;
use App\Trading\PortfolioValuation;
use App\Trading\SignalPresenter;
use App\Trading\TradingHalt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public const LATEST_SIGNALS = 12;

    public function index(Request $request, PortfolioValuation $valuation, TradingHalt $halt): Response
    {
        $portfolios = $request->user()->portfolios()->latest()->get()
            ->map(fn (Portfolio $portfolio) => [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'market' => $portfolio->market->value,
                'mode' => $portfolio->mode->value,
                'status' => $portfolio->status->value,
                'initial_cash' => $portfolio->initial_cash,
                'equity' => round($valuation->equity($portfolio), 2),
                'starts_at' => $portfolio->starts_at->toDateString(),
                'ends_at' => $portfolio->ends_at->toDateString(),
                'auto_execute' => $portfolio->auto_execute,
            ]);

        return Inertia::render('portfolios/Index', [
            'portfolios' => $portfolios,
            'halted' => $halt->isHalted(),
        ]);
    }

    public function store(StorePortfolioRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $portfolio = $request->user()->portfolios()->create([
            ...$data,
            'risk_profile_id' => RiskProfile::firstOrCreate(['name' => 'Padrão'])->id,
            'mode' => PortfolioMode::Paper,
            'broker' => 'paper',
            'status' => PortfolioStatus::Running,
            'cash' => $data['initial_cash'],
            'auto_execute' => $data['auto_execute'] ?? false,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Carteira criada.']);

        return to_route('portfolios.show', $portfolio);
    }

    public function update(UpdatePortfolioRequest $request, Portfolio $portfolio, LiveLimits $limits): RedirectResponse
    {
        $autoExecute = $request->boolean('auto_execute');
        $actor = 'user:'.$request->user()->id;
        $live = $portfolio->isLive();
        $changed = $autoExecute !== $portfolio->auto_execute || ($live && $autoExecute && ! $portfolio->autoExecutesLive());

        if ($changed) {
            $portfolio->forceFill(['auto_execute' => $autoExecute]);

            if ($live) {
                // CONFIRMO was validated; turning it on also lifts a previous "Parar tudo".
                $portfolio->forceFill([
                    'live_auto_confirmed_at' => $autoExecute ? now() : null,
                    'orders_blocked_at' => $autoExecute ? null : $portfolio->orders_blocked_at,
                    'orders_blocked_reason' => $autoExecute ? null : $portfolio->orders_blocked_reason,
                ]);
            }

            $portfolio->save();

            AuditLog::record($actor, 'portfolio.auto_execute', $portfolio, [
                'auto_execute' => $autoExecute,
                'live' => $live,
                'max_order_value' => $live ? $limits->maxOrderValue() : null,
                'max_daily_value' => $live ? $limits->maxDailyValue() : null,
                'testnet' => $live ? (bool) config('trading.binance.testnet') : null,
            ]);
        }

        if ($live && $request->has('notify_live_email') && $request->boolean('notify_live_email') !== $portfolio->notify_live_email) {
            $portfolio->update(['notify_live_email' => $request->boolean('notify_live_email')]);
            AuditLog::record($actor, 'portfolio.notify_live_email', $portfolio, ['notify_live_email' => $portfolio->notify_live_email]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $autoExecute && $live => 'Execução automática REAL ligada: sinais aprovados serão enviados à Binance sem confirmação, dentro dos limites.',
            $autoExecute => 'Execução automática ligada: sinais aprovados serão executados sem confirmação.',
            default => 'Execução automática desligada: sinais aprovados aguardam a sua confirmação.',
        }]);

        return back();
    }

    public function show(Request $request, Portfolio $portfolio, PortfolioValuation $valuation, PerformanceReport $performance, TaxReport $tax, AgentActivity $activity, PortfolioCharts $charts, LiveLimits $limits, TradingHalt $halt): Response
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        $holdings = collect($portfolio->sync_report['holdings'] ?? [])->keyBy('coin');

        // Só o sinal mais recente de cada ativo; o histórico completo fica em portfolios.signals.
        $signals = $portfolio->signals()
            ->whereIn('id', Signal::query()->where('portfolio_id', $portfolio->id)->selectRaw('max(id)')->groupBy('asset_id'))
            ->with(['asset', 'votes'])
            ->orderByDesc('id')
            ->limit(self::LATEST_SIGNALS)
            ->get()
            ->map(fn (Signal $signal) => SignalPresenter::present($signal));

        return Inertia::render('portfolios/Show', [
            'portfolio' => [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'market' => $portfolio->market->value,
                'mode' => $portfolio->mode->value,
                'status' => $portfolio->status->value,
                'initial_cash' => $portfolio->initial_cash,
                'cash' => round($portfolio->cash, 2),
                'equity' => round($valuation->equity($portfolio), 2),
                'starts_at' => $portfolio->starts_at->toDateString(),
                'ends_at' => $portfolio->ends_at->toDateString(),
                'auto_execute' => $portfolio->auto_execute,
                'orders_blocked' => $portfolio->isOrdersBlocked(),
                'orders_blocked_reason' => $portfolio->orders_blocked_reason,
                'notify_live_email' => $portfolio->notify_live_email,
            ],
            'live' => $portfolio->isLive() ? [
                'enabled' => $limits->enabled(),
                'testnet' => (bool) config('trading.binance.testnet'),
                'max_order_value' => $limits->maxOrderValue(),
                'max_daily_value' => $limits->maxDailyValue(),
                'remaining_today' => round($limits->remainingToday($portfolio), 2),
                'halted' => $halt->isHalted(),
            ] : null,
            'positions' => $portfolio->openPositions()->with('asset')->get()->map(fn (Position $position) => [
                'id' => $position->id,
                'symbol' => $position->asset->symbol,
                'qty' => $position->qty,
                'avg_price' => $position->avg_price,
                'stop_price' => $position->stop_price,
                'last_price' => $holdings->get(preg_replace('/USDT$/', '', $position->asset->symbol))['price'] ?? null,
                'value' => $holdings->get(preg_replace('/USDT$/', '', $position->asset->symbol))['value'] ?? null,
            ]),
            'account_sync' => $portfolio->broker === 'binance' && $portfolio->mode === PortfolioMode::Live ? [
                'synced_at' => $portfolio->synced_at?->toIso8601String(),
                'dust' => $holdings->where('status', 'poeira')->values(),
                'dust_total' => round($holdings->where('status', 'poeira')->sum('value'), 2),
                'not_mirrored' => $holdings->whereIn('status', ['sem_cotacao', 'sem_par_usdt'])->values(),
            ] : null,
            'signals' => $signals,
            'signals_total' => $portfolio->signals()->count(),
            'performance' => $performance->build($portfolio),
            'agent_scores' => AgentScore::query()->where('market', $portfolio->market)->orderByDesc('hit_rate')->get()->map(fn (AgentScore $score) => [
                'agent' => $score->agent->value,
                'signals' => $score->signals,
                'hits' => $score->hits,
                'hit_rate' => $score->hit_rate,
                'weight' => $score->weight,
            ]),
            'tax_months' => $tax->months($portfolio),
            'agent_activity' => $activity->for($portfolio),
            'charts' => $charts->for($portfolio),
        ]);
    }
}
