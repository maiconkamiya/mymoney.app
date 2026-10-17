<?php

namespace App\Trading\Micro;

use App\Enums\Market;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\Portfolio;
use Illuminate\Support\Collection;

/**
 * Orders the crypto assets by recent volatility (average 1-minute range) so
 * the trader looks first at the pairs that move the most, and reads each one.
 */
class MicroRanker
{
    public function __construct(private readonly MicroAnalysis $analysis) {}

    /**
     * @return Collection<int, array{asset: Asset, setup: MicroSetup}> most volatile first
     */
    public function rank(Portfolio $portfolio, float $grossMarginPct): Collection
    {
        $limit = (int) config('trading.micro.ranking_size');
        $maxAge = (int) config('trading.micro.max_candle_age_seconds');

        return $this->assets($portfolio)
            ->map(function (Asset $asset) use ($grossMarginPct, $maxAge) {
                $candles = Candle::query()
                    ->where('asset_id', $asset->id)
                    ->where('timeframe', '1m')
                    ->where('open_at', '>=', now()->subMinutes((int) config('trading.micro.lookback_minutes')))
                    ->orderBy('open_at')
                    ->get();

                if ($candles->isEmpty() || $candles->last()->open_at->copy()->addMinute()->diffInSeconds(now(), false) > $maxAge) {
                    return null;
                }

                $setup = $this->analysis->read(
                    $candles->map(fn (Candle $c) => ['high' => (float) $c->high, 'low' => (float) $c->low, 'close' => (float) $c->close])->all(),
                    $grossMarginPct,
                );

                return $setup === null ? null : ['asset' => $asset, 'setup' => $setup];
            })
            ->filter()
            ->sortByDesc(fn (array $row) => $row['setup']->atrPct)
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assets(Portfolio $portfolio): Collection
    {
        return Asset::query()
            ->where('market', Market::Crypto)
            ->where('active', true)
            ->where('is_benchmark', false)
            ->orderBy('symbol')
            ->get();
    }
}
