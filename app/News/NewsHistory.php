<?php

namespace App\News;

use App\Models\Asset;
use App\Models\Candle;
use App\Models\NewsItem;
use App\News\Embeddings\SimilarNewsFinder;
use Carbon\CarbonInterface;

/**
 * Historical context for the news agent: for the news now moving an asset,
 * the most similar past news and what the price did in the hours after them.
 * Only past data is used: both the news and the price move end before now().
 */
class NewsHistory
{
    public function __construct(private readonly SimilarNewsFinder $finder) {}

    /**
     * @param  iterable<NewsItem>  $current  The news in the scenario.
     * @return list<array<string, mixed>>
     */
    public function forAsset(Asset $asset, iterable $current): array
    {
        $limit = (int) config('news.history.max_current_news');
        $history = [];
        $seen = [];

        foreach ($current as $news) {
            if ($limit-- <= 0) {
                break;
            }

            if ($news->embedding === null) {
                continue;
            }

            $matches = $this->finder->nearest(
                $news->embedding,
                (float) config('news.history.min_similarity'),
                (int) config('news.history.per_news'),
                ['asOf' => $news->fetched_at, 'assetId' => $asset->id, 'exceptId' => $news->id],
            );

            foreach ($matches as $match) {
                $past = $match['news'];

                if (isset($seen[$past->id])) {
                    continue;
                }

                $seen[$past->id] = true;

                $history[] = [
                    'id' => "news:{$past->id}",
                    'similar_to' => "news:{$news->id}",
                    'title' => $past->title,
                    'fetched_at' => $past->fetched_at->toIso8601String(),
                    'similarity' => round($match['similarity'], 3),
                    'horizon_hours' => (int) config('news.history.horizon_hours'),
                    'price_change_pct' => $this->priceChange($asset, $past->fetched_at),
                ];
            }
        }

        return $history;
    }

    /**
     * Percentage move between the price when the news appeared and the price
     * after the horizon; null when either price is unknown or the horizon is
     * still in the future.
     */
    private function priceChange(Asset $asset, CarbonInterface $from): ?float
    {
        $to = $from->copy()->addHours((int) config('news.history.horizon_hours'));

        if ($to->isFuture()) {
            return null;
        }

        $start = $this->closeAt($asset, $from);
        $end = $this->closeAt($asset, $to);

        if ($start === null || $end === null || $start == 0.0) {
            return null;
        }

        return round(($end - $start) / $start * 100, 2);
    }

    private function closeAt(Asset $asset, CarbonInterface $at): ?float
    {
        $candle = Candle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->where('open_at', '<=', $at)
            ->where('open_at', '>=', $at->copy()->subHours(6))
            ->orderByDesc('open_at')
            ->first();

        return $candle === null ? null : (float) $candle->close;
    }
}
