<?php

namespace App\Http\Controllers;

use App\Agents\AgentActivity;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Http\Requests\StorePortfolioRequest;
use App\Http\Requests\UpdatePortfolioRequest;
use App\Models\AgentScore;
use App\Models\AgentVote;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Reports\PerformanceReport;
use App\Reports\TaxReport;
use App\Trading\PortfolioCharts;
use App\Trading\PortfolioValuation;
use App\Trading\TradingHalt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
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

    public function update(UpdatePortfolioRequest $request, Portfolio $portfolio): RedirectResponse
    {
        $autoExecute = $request->boolean('auto_execute');

        if ($autoExecute !== $portfolio->auto_execute) {
            $portfolio->update(['auto_execute' => $autoExecute]);
            AuditLog::record('user:'.$request->user()->id, 'portfolio.auto_execute', $portfolio, ['auto_execute' => $autoExecute]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $autoExecute
            ? 'Execução automática ligada: sinais aprovados serão executados sem confirmação.'
            : 'Execução automática desligada: sinais aprovados aguardam a sua confirmação.']);

        return back();
    }

    public function show(Request $request, Portfolio $portfolio, PortfolioValuation $valuation, PerformanceReport $performance, TaxReport $tax, AgentActivity $activity, PortfolioCharts $charts): Response
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        $signals = $portfolio->signals()
            ->with(['asset', 'votes'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Signal $signal) => [
                'id' => $signal->id,
                'symbol' => $signal->asset->symbol,
                'stage' => $signal->stage->value,
                'side' => $signal->side?->value,
                'score' => $signal->score,
                'entry_price' => $signal->entry_price,
                'stop_price' => $signal->stop_price,
                'target_price' => $signal->target_price,
                'reason' => $signal->decision_reason,
                'created_at' => $signal->created_at->toIso8601String(),
                'cost' => round($signal->votes->sum('cost'), 4),
                'votes' => $signal->votes->sortBy([['round', 'desc']])->unique(fn (AgentVote $vote) => $vote->agent->value)->values()->map(fn (AgentVote $vote) => [
                    'agent' => $vote->agent->value,
                    'round' => $vote->round,
                    'action' => $vote->action->value,
                    'confidence' => $vote->confidence,
                    'veto' => $vote->veto,
                    'reasons' => $vote->reasons ?? [],
                ]),
            ]);

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
            ],
            'positions' => $portfolio->openPositions()->with('asset')->get()->map(fn (Position $position) => [
                'id' => $position->id,
                'symbol' => $position->asset->symbol,
                'qty' => $position->qty,
                'avg_price' => $position->avg_price,
                'stop_price' => $position->stop_price,
            ]),
            'signals' => $signals,
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
