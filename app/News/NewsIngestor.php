<?php

namespace App\News;

use App\Models\NewsItem;
use App\News\Embeddings\Embedder;
use App\News\Embeddings\SimilarNewsFinder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class NewsIngestor
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly SimilarNewsFinder $finder,
    ) {}

    /**
     * Store items not seen before and return the ones worth triaging. An item
     * is skipped when its canonical URL was seen, and stored but flagged as a
     * duplicate (not returned) when it is semantically close to an earlier one.
     *
     * @param  iterable<array{url: string, title: string, body: string|null, published_at: string|null}>  $items
     * @return list<NewsItem>
     */
    public function ingest(string $source, iterable $items): array
    {
        $created = [];

        foreach ($items as $item) {
            if ($item['url'] === '' || $item['title'] === '') {
                continue;
            }

            $urlHash = NewsItem::hashUrl($item['url']);

            if (NewsItem::query()->where('url_hash', $urlHash)->exists()) {
                continue;
            }

            $embedding = $this->embed($item['title'], $item['body']);
            $duplicateOf = $embedding === null ? null : $this->findDuplicate($embedding);

            $news = NewsItem::firstOrCreate(
                ['url_hash' => $urlHash],
                [
                    'source' => $source,
                    'url' => $item['url'],
                    'title' => mb_substr($item['title'], 0, 255),
                    'body' => $item['body'],
                    'published_at' => $this->parseDate($item['published_at']),
                    'fetched_at' => now(),
                    'embedding' => $embedding,
                    'duplicate_of_id' => $duplicateOf,
                ],
            );

            if ($news->wasRecentlyCreated && $news->duplicate_of_id === null) {
                $created[] = $news;
            }
        }

        return $created;
    }

    /**
     * Embedding failures never block ingestion: the item is stored without a
     * vector and goes through triage as if it were new.
     *
     * @return list<float>|null
     */
    private function embed(string $title, ?string $body): ?array
    {
        try {
            return $this->embedder->embed(self::textFor($title, $body));
        } catch (Throwable $e) {
            Log::warning('News embedding failed', ['title' => $title, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  list<float>  $embedding
     */
    private function findDuplicate(array $embedding): ?int
    {
        $match = $this->finder->nearest(
            $embedding,
            (float) config('news.dedup_threshold'),
            1,
            ['since' => now()->subHours((int) config('news.dedup_window_hours'))],
        );

        return $match[0]['news']->id ?? null;
    }

    public static function textFor(string $title, ?string $body): string
    {
        return trim($title."\n".mb_substr(strip_tags((string) $body), 0, 500));
    }

    private function parseDate(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
