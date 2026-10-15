<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * How one news item affects one asset, as judged by the news agent.
 *
 * @property int $id
 * @property int $news_item_id
 * @property int $asset_id
 * @property float $sentiment
 * @property float $relevance
 * @property bool $is_new_fact
 * @property array<string, mixed>|null $analysis
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read NewsItem $newsItem
 */
class NewsAsset extends Pivot
{
    protected $table = 'news_asset';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sentiment' => 'float',
            'relevance' => 'float',
            'is_new_fact' => 'boolean',
            'analysis' => 'array',
        ];
    }

    /** @return BelongsTo<NewsItem, $this> */
    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
