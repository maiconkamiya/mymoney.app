<?php

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['symbol', 'market', 'name', 'quote_currency', 'lot_size', 'tick_size', 'sector', 'active'])]
class Asset extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'lot_size' => 'decimal:8',
            'tick_size' => 'decimal:8',
            'active' => 'boolean',
        ];
    }

    public function candles(): HasMany
    {
        return $this->hasMany(Candle::class);
    }

    public function news(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class, 'news_asset')
            ->withPivot(['sentiment', 'relevance', 'is_new_fact', 'analysis'])
            ->withTimestamps();
    }
}
