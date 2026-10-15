<?php

namespace App\Agents;

use App\Agents\Data\Scenario;
use App\Enums\Market;
use App\MarketData\B3Fundamentals;
use App\Models\Candle;
use App\Models\NewsAsset;
use App\Models\Signal;
use App\News\NewsHistory;
use App\Trading\Contracts\PriceFeed;
use App\Trading\PortfolioValuation;

class ScenarioBuilder
{
    public function __construct(
        private readonly PriceFeed $prices,
        private readonly PortfolioValuation $valuation,
        private readonly B3Fundamentals $fundamentals,
        private readonly NewsHistory $history,
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
        $links = NewsAsset::query()
            ->with('newsItem')
            ->where('asset_id', $asset->id)
            ->whereHas('newsItem', fn ($query) => $query
                ->where('fetched_at', '<=', now())
                ->where('fetched_at', '>=', now()->subHours(48)))
            ->orderByDesc('relevance')
            ->limit(10)
            ->get();

        $news = $links
            ->map(fn (NewsAsset $link) => [
                'id' => "news:{$link->newsItem->id}",
                'title' => $link->newsItem->title,
                'source' => $link->newsItem->source,
                'published_at' => $link->newsItem->published_at?->toIso8601String(),
                'fetched_at' => $link->newsItem->fetched_at->toIso8601String(),
                'sentiment' => $link->sentiment,
                'relevance' => $link->relevance,
                'is_new_fact' => $link->is_new_fact,
            ])
            ->all();

        $similarNews = $this->history->forAsset($asset, $links->map(fn (NewsAsset $link) => $link->newsItem));

        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->first();

        return new Scenario(
            symbol: $asset->symbol,
            market: $asset->market->value,
            price: $quote->price,
            asOf: $quote->at->toIso8601String(),
            candles: array_values($candles),
            news: array_values($news),
            similarNews: $similarNews,
            position: $position ? ['qty' => $position->qty, 'avg_price' => $position->avg_price, 'stop' => $position->stop_price] : null,
            cash: $portfolio->cash,
            equity: $this->valuation->equity($portfolio),
            fundamentals: $asset->market === Market::B3 ? $this->fundamentals->for($asset) : null,
        );
    }
}
