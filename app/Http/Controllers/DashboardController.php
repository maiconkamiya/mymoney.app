<?php

namespace App\Http\Controllers;

use App\Enums\PortfolioStatus;
use App\Enums\SignalStage;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Trading\PortfolioValuation;
use App\Trading\TradingHalt;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PortfolioValuation $valuation, TradingHalt $halt): Response
    {
        $portfolios = $request->user()->portfolios()
            ->where('status', PortfolioStatus::Running)
            ->latest()
            ->get();

        $pending = Signal::query()
            ->with(['asset', 'portfolio'])
            ->whereIn('portfolio_id', $portfolios->modelKeys())
            ->where('stage', SignalStage::Approved)
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('Dashboard', [
            'halted' => $halt->isHalted(),
            'halted_live_only' => $halt->isLiveOnly(),
            'portfolios' => $portfolios->map(fn (Portfolio $portfolio) => [
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
            ])->values(),
            'pending_signals' => $pending->map(fn (Signal $signal) => [
                'id' => $signal->id,
                'portfolio_id' => $signal->portfolio_id,
                'portfolio' => $signal->portfolio->name,
                'symbol' => $signal->asset->symbol,
                'side' => $signal->side?->value,
                'score' => $signal->score,
                'created_at' => $signal->created_at->toIso8601String(),
            ])->values(),
        ]);
    }
}
