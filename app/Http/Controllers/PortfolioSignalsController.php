<?php

namespace App\Http\Controllers;

use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Trading\SignalPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every signal of a portfolio, newest first, loaded page by page.
 */
class PortfolioSignalsController extends Controller
{
    public const PER_PAGE = 20;

    public function __invoke(Request $request, Portfolio $portfolio): Response
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        $filters = $request->validate([
            'ativo' => ['nullable', 'string', 'max:30'],
            'resultado' => ['nullable', 'string', Rule::enum(SignalStage::class)],
        ]);

        $signals = $portfolio->signals()
            ->with(['asset', 'votes'])
            ->when($filters['ativo'] ?? null, fn ($query, string $symbol) => $query->whereHas('asset', fn ($asset) => $asset->where('symbol', $symbol)))
            ->when($filters['resultado'] ?? null, fn ($query, string $stage) => $query->where('stage', $stage))
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Signal $signal) => SignalPresenter::present($signal));

        return Inertia::render('portfolios/Sinais', [
            'portfolio' => [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'market' => $portfolio->market->value,
            ],
            'signals' => Inertia::scroll($signals),
            'filters' => [
                'ativo' => $filters['ativo'] ?? '',
                'resultado' => $filters['resultado'] ?? '',
            ],
            'symbols' => Asset::query()
                ->whereIn('id', $portfolio->signals()->select('asset_id'))
                ->orderBy('symbol')
                ->pluck('symbol'),
        ]);
    }
}
