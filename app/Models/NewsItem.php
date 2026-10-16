<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $source
 * @property string $url
 * @property string $url_hash
 * @property string $title
 * @property string|null $body
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable $fetched_at
 * @property list<float>|null $embedding
 * @property int|null $duplicate_of_id
 * @property CarbonImmutable|null $triaged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Asset> $assets
 */
#[Fillable(['source', 'url', 'url_hash', 'title', 'body', 'published_at', 'fetched_at', 'embedding', 'duplicate_of_id', 'triaged_at'])]
class NewsItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
            'triaged_at' => 'datetime',
            'embedding' => 'array',
        ];
    }

    public static function hashUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $canonical = strtolower(($parts['host'] ?? '').rtrim($parts['path'] ?? '', '/'));

        return hash('sha256', $canonical);
    }

    /** @return BelongsToMany<Asset, $this, NewsAsset> */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'news_asset')
            ->using(NewsAsset::class)
            ->withPivot(['sentiment', 'relevance', 'is_new_fact', 'analysis'])
            ->withTimestamps();
    }
}
