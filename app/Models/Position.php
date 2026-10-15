<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property float $qty
 * @property float $avg_price
 * @property float|null $stop_price
 * @property float $realized_pnl
 */
#[Fillable(['portfolio_id', 'asset_id', 'qty', 'avg_price', 'stop_price', 'realized_pnl', 'opened_at', 'closed_at'])]
class Position extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'avg_price' => 'float',
            'stop_price' => 'float',
            'realized_pnl' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
