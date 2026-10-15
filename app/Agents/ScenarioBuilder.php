<?php

namespace App\Agents;

use App\Agents\Data\Scenario;
use App\Models\Candle;
use App\Models\NewsItem;
use App\Models\Signal;
use App\Trading\Contracts\PriceFeed;
use App\Trading\PortfolioValuation;

class ScenarioBuilder
{
    public function __construct(
        private readonly PriceFeed $prices,
        private readonly PortfolioValuation $valuation,
    ) {}

    public function build(Signal $signal): ?Scenario
    {
        $asset = $signal->asset;
        $portfolio = $signal->portfolio;
        $quote = $this->prices->quote($asset);

        if ($quote === null) {
            return null;
        }

        $candles = Candle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->latest('open_at')
            ->limit(60)
            ->get()
            ->reverse()
            ->map(fn (Candle $c) => [
                'id' => "candle:{$asset->symbol}:1m:{$c->open_at->toIso8601String()}",
                'open' => (float) $c->open,
                'high' => (float) $c->high,
                'low' => (float) $c->low,
                'close' => (float) $c->close,
                'volume' => (float) $c->volume,
            ])
            ->values()
            ->all();

        // Only news that already existed "now": simulations must never see the future.
        $news = $asset->news()
            ->where('fetched_at', '<=', now())
            ->where('fetched_at', '>=', now()->subHours(48))
            ->orderByDesc('news_asset.relevance')
            ->limit(10)
            ->get()
            ->map(fn (NewsItem $n) => [
                'id' => "news:{$n->id}",
                'title' => $n->title,
                'source' => $n->source,
                'published_at' => $n->published_at?->toIso8601String(),
                'fetched_at' => $n->fetched_at->toIso8601String(),
                'sentiment' => (float) $n->pivot->sentiment,
                'relevance' => (float) $n->pivot->relevance,
                'is_new_fact' => (bool) $n->pivot->is_new_fact,
            ])
            ->all();

        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->first();

        return new Scenario(
            symbol: $asset->symbol,
            market: $asset->market->value,
            price: $quote->price,
            asOf: $quote->at->toIso8601String(),
            candles: $candles,
            news: $news,
            position: $position ? ['qty' => $position->qty, 'avg_price' => $position->avg_price, 'stop' => $position->stop_price] : null,
            cash: $portfolio->cash,
            equity: $this->valuation->equity($portfolio),
        );
    }
}
