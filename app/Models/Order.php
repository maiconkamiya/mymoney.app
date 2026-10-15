<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['portfolio_id', 'signal_id', 'asset_id', 'broker', 'external_id', 'side', 'type', 'qty', 'price', 'stop_price', 'status', 'fees', 'reject_reason', 'filled_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'qty' => 'float',
            'price' => 'float',
            'stop_price' => 'float',
            'fees' => 'float',
            'filled_at' => 'datetime',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class);
    }
}
