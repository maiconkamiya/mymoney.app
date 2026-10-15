<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['source', 'url', 'url_hash', 'title', 'body', 'published_at', 'fetched_at', 'embedding', 'triaged_at'])]
class NewsItem extends Model
{
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

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'news_asset')
            ->withPivot(['sentiment', 'relevance', 'is_new_fact', 'analysis'])
            ->withTimestamps();
    }
}
