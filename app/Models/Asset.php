<?php

namespace App\Models;

use App\Enums\Market;
use Carbon\CarbonImmutable;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $symbol
 * @property Market $market
 * @property string|null $name
 * @property string $quote_currency
 * @property string $lot_size
 * @property string $tick_size
 * @property string|null $min_qty
 * @property string|null $min_notional
 * @property string|null $sector
 * @property bool $active
 * @property bool $is_benchmark
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Candle> $candles
 * @property-read Collection<int, NewsItem> $news
 */
#[Fillable(['symbol', 'market', 'name', 'quote_currency', 'lot_size', 'tick_size', 'min_qty', 'min_notional', 'sector', 'active', 'is_benchmark'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'lot_size' => 'decimal:8',
            'tick_size' => 'decimal:8',
            'min_qty' => 'decimal:8',
            'min_notional' => 'decimal:8',
            'active' => 'boolean',
            'is_benchmark' => 'boolean',
        ];
    }

    /** @return HasMany<Candle, $this> */
    public function candles(): HasMany
    {
        return $this->hasMany(Candle::class);
    }

    /** @return BelongsToMany<Portfolio, $this> */
    public function portfolios(): BelongsToMany
    {
        return $this->belongsToMany(Portfolio::class, 'portfolio_asset')->withTimestamps();
    }

    /** @return BelongsToMany<NewsItem, $this, NewsAsset> */
    public function news(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class, 'news_asset')
            ->using(NewsAsset::class)
            ->withPivot(['sentiment', 'relevance', 'is_new_fact', 'analysis'])
            ->withTimestamps();
    }
}
