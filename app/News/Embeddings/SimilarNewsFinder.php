<?php

namespace App\News\Embeddings;

use App\Models\NewsItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Nearest-neighbour search over news embeddings. Uses pgvector when the
 * database is Postgres and an in-memory cosine scan otherwise (tests, SQLite).
 * Never returns news fetched after $asOf, so simulations cannot see the future.
 */
class SimilarNewsFinder
{
    /**
     * @param  list<float>  $embedding
     * @param  array{asOf?: CarbonInterface|null, since?: CarbonInterface|null, assetId?: int|null, exceptId?: int|null, includeDuplicates?: bool}  $filters
     * @return list<array{news: NewsItem, similarity: float}>
     */
    public function nearest(array $embedding, float $minSimilarity, int $limit, array $filters = []): array
    {
        $query = $this->candidates($filters);

        return $this->usesPgvector()
            ? $this->searchWithPgvector($query, $embedding, $minSimilarity, $limit)
            : $this->searchInMemory($query, $embedding, $minSimilarity, $limit);
    }

    public function usesPgvector(): bool
    {
        $store = config('news.vector_store', 'auto');

        return $store === 'pgvector' || ($store === 'auto' && DB::connection()->getDriverName() === 'pgsql');
    }

    /**
     * @param  array{asOf?: CarbonInterface|null, since?: CarbonInterface|null, assetId?: int|null, exceptId?: int|null, includeDuplicates?: bool}  $filters
     * @return Builder<NewsItem>
     */
    private function candidates(array $filters): Builder
    {
        $query = NewsItem::query()
            ->whereNotNull('embedding')
            ->where('fetched_at', '<=', $filters['asOf'] ?? now());

        if (isset($filters['since'])) {
            $query->where('fetched_at', '>=', $filters['since']);
        }

        if (isset($filters['exceptId'])) {
            $query->whereKeyNot($filters['exceptId']);
        }

        if (isset($filters['assetId'])) {
            $assetId = $filters['assetId'];
            $query->whereHas('assets', fn (Builder $assets) => $assets->where('assets.id', $assetId));
        }

        if (! ($filters['includeDuplicates'] ?? false)) {
            $query->whereNull('duplicate_of_id');
        }

        return $query;
    }

    /**
     * @param  Builder<NewsItem>  $query
     * @param  list<float>  $embedding
     * @return list<array{news: NewsItem, similarity: float}>
     */
    private function searchInMemory(Builder $query, array $embedding, float $minSimilarity, int $limit): array
    {
        $scored = [];

        $query->latest('fetched_at')
            ->limit((int) config('news.memory_scan_limit', 2000))
            ->get()
            ->each(function (NewsItem $news) use ($embedding, $minSimilarity, &$scored): void {
                $similarity = self::cosine($embedding, $news->embedding ?? []);

                if ($similarity >= $minSimilarity) {
                    $scored[] = ['news' => $news, 'similarity' => $similarity];
                }
            });

        usort($scored, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @param  Builder<NewsItem>  $query
     * @param  list<float>  $embedding
     * @return list<array{news: NewsItem, similarity: float}>
     */
    private function searchWithPgvector(Builder $query, array $embedding, float $minSimilarity, int $limit): array
    {
        $vector = json_encode($embedding);

        $rows = $query
            ->select('news_items.*')
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$vector])
            ->whereRaw('1 - (embedding <=> ?::vector) >= ?', [$vector, $minSimilarity])
            ->orderByRaw('embedding <=> ?::vector', [$vector])
            ->limit($limit)
            ->get();

        $found = [];

        foreach ($rows as $news) {
            $found[] = ['news' => $news, 'similarity' => (float) $news->getAttribute('similarity')];
        }

        return $found;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        return $normA == 0.0 || $normB == 0.0 ? 0.0 : $dot / (sqrt($normA) * sqrt($normB));
    }
}
