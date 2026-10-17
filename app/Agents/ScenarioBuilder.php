<?php

namespace App\Agents;

use App\Agents\Chart\ChartAnalyzer;
use App\Agents\Data\Scenario;
use App\Enums\Market;
use App\Forecast\ForecastClient;
use App\MarketData\B3Fundamentals;
use App\Models\Candle;
use Carbon\CarbonImmutable;
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
        private readonly ForecastClient $forecasts,
        private readonly ChartAnalyzer $chart,
    ) {}

    public function build(Signal $signal): ?Scenario
    {
        $asset = $signal->asset;
        $portfolio = $signal->portfolio;
        $quote = $this->prices->quote($asset);

        if ($quote === null) {
            return null;
        }

        // Never past "now": simulations must not see the future.
        $rows = Candle::query()
            ->toBase()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->where('open_at', '<=', now())
            ->orderByDesc('open_at')
            ->limit((int) config('agents.chart.max_candles'))
            ->get(['open_at', 'open', 'high', 'low', 'close', 'volume'])
            ->reverse()
            ->values();

        $bars = $rows->map(fn (object $row) => [
            't' => CarbonImmutable::parse($row->open_at)->getTimestamp(),
            'o' => (float) $row->open,
            'h' => (float) $row->high,
            'l' => (float) $row->low,
            'c' => (float) $row->close,
            'v' => (float) $row->volume,
        ])->all();

        $candles = $rows->slice(-60)->map(fn (object $row) => [
            'id' => "candle:{$asset->symbol}:1m:".CarbonImmutable::parse($row->open_at)->toIso8601String(),
            'open' => (float) $row->open,
            'high' => (float) $row->high,
            'low' => (float) $row->low,
            'close' => (float) $row->close,
            'volume' => (float) $row->volume,
        ])->values()->all();

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

        $news = array_values($news);

        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->first();

        return new Scenario(
            symbol: $asset->symbol,
            market: $asset->market->value,
            price: $quote->price,
            asOf: $quote->at->toIso8601String(),
            candles: $candles,
            news: $news,
            similarNews: $similarNews,
            position: $position ? ['qty' => $position->qty, 'avg_price' => $position->avg_price, 'stop' => $position->stop_price] : null,
            cash: $portfolio->cash,
            equity: $this->valuation->equity($portfolio),
            fundamentals: $asset->market === Market::B3 ? $this->fundamentals->for($asset) : null,
            forecast: $this->forecasts->predict($asset->symbol, $asset->market->value, $candles, $news),
            chart: $this->chart->analyze($bars, now()->getTimestamp()),
        );
    }
}
