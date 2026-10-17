<?php

namespace App\Trading;

use App\Models\Asset;
use App\Models\Candle;
use App\Models\Portfolio;
use App\Trading\B3\MarketPhase;

/**
 * Dados dos gráficos da tela da carteira: os ativos que ela opera, com a série
 * de candles de 1 minuto já gravada e as linhas de preço médio e stop.
 */
class PortfolioCharts
{
    public const MAX_ASSETS = 8;

    public const CANDLES = 90;

    public function __construct(private readonly MarketHours $hours) {}

    /**
     * @return array{market_open: bool, phase: string, assets: list<array<string, mixed>>}
     */
    public function for(Portfolio $portfolio): array
    {
        $positions = $portfolio->openPositions()->get()->keyBy('asset_id');
        $signaled = $portfolio->signals()->latest()->limit(50)->pluck('asset_id')->unique();

        $assets = Asset::query()
            ->where('market', $portfolio->market)
            ->where('active', true)
            ->where('is_benchmark', false)
            ->when($portfolio->assets()->exists(), fn ($query) => $query->whereIn('id', $portfolio->assets()->select('assets.id')))
            ->orderBy('symbol')
            ->get()
            ->sortBy(fn (Asset $asset) => match (true) {
                $positions->has($asset->id) => 0,
                $signaled->contains($asset->id) => 1,
                default => 2,
            })
            ->take(self::MAX_ASSETS)
            ->values();

        $phase = $this->hours->phase($portfolio->market);

        return [
            'market_open' => $phase === MarketPhase::Continuous,
            'phase' => $phase->value,
            'assets' => array_values($assets->map(fn (Asset $asset) => $this->asset($asset, $positions->get($asset->id)))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(Asset $asset, mixed $position): array
    {
        $series = Candle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->orderByDesc('open_at')
            ->limit(self::CANDLES)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Candle $c) => [
                $c->open_at->getTimestampMs(),
                (float) $c->open,
                (float) $c->high,
                (float) $c->low,
                (float) $c->close,
                (float) $c->volume,
            ])
            ->all();

        return [
            'symbol' => $asset->symbol,
            'name' => $asset->name,
            'quote_currency' => $asset->quote_currency,
            'series' => $series,
            'position' => $position ? [
                'qty' => $position->qty,
                'avg_price' => $position->avg_price,
                'stop_price' => $position->stop_price,
            ] : null,
        ];
    }
}
