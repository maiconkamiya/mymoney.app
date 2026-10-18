<?php

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Insights\AssetInsightWriter;
use App\Insights\FearGreedIndex;
use App\Insights\PriceHistory;
use App\Models\Asset;
use App\Models\AssetInsight;
use App\Settings\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página do ativo, como a da Binance: histórico de preços (24h a 1y), sentimento
 * do mercado cripto e a análise do dia. Histórico e sentimento chegam depois da
 * página abrir, porque dependem de fontes de fora.
 */
class AssetInsightController extends Controller
{
    public function show(Request $request, Asset $asset, PriceHistory $history, FearGreedIndex $sentiment, SystemSettings $settings): Response
    {
        abort_unless($asset->market === Market::Crypto && $asset->active && ! $asset->is_benchmark, 404);

        return Inertia::render('assets/Show', [
            'asset' => [
                'id' => $asset->id,
                'symbol' => $asset->symbol,
                'base' => preg_replace('/USDT$/', '', $asset->symbol),
                'name' => $asset->name,
            ],
            'insight' => $this->insight($asset),
            'ai_enabled' => (bool) config('insights.ai.enabled'),
            'can_edit_settings' => $settings->canEdit($request->user()),
            'history' => Inertia::defer(fn () => $history->for($asset->symbol)),
            'sentiment' => Inertia::defer(fn () => $sentiment->current()),
        ]);
    }

    /**
     * "Atualizar análise": uma chamada nova, salvo se a última é recente.
     */
    public function store(Asset $asset, AssetInsightWriter $writer): RedirectResponse
    {
        abort_unless($asset->market === Market::Crypto && $asset->active && ! $asset->is_benchmark, 404);

        if (! config('insights.ai.enabled')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'A análise do dia está desligada em Configurações > Custos e serviços.']);

            return back();
        }

        $recent = AssetInsight::query()
            ->where('asset_id', $asset->id)
            ->where('created_at', '>=', now()->subMinutes(max(1, (int) config('insights.ai.min_interval_minutes'))))
            ->exists();

        if ($recent) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'A análise já é dos últimos minutos.']);

            return back();
        }

        Inertia::flash('toast', $writer->write($asset) === null
            ? ['type' => 'error', 'message' => 'Não foi possível escrever a análise agora. Tente de novo em instantes.']
            : ['type' => 'success', 'message' => 'Análise atualizada.']);

        return back();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function insight(Asset $asset): ?array
    {
        $insight = AssetInsight::query()->where('asset_id', $asset->id)->latest('id')->first();

        return $insight === null ? null : [
            'direction' => $insight->direction,
            'change_24h_pct' => $insight->change_24h_pct,
            'summary' => $insight->summary,
            'points' => $insight->points,
            'news_count' => $insight->news_count,
            'model' => $insight->model,
            'cost' => $insight->cost,
            'created_at' => $insight->created_at->toIso8601String(),
        ];
    }
}
