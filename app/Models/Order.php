<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $portfolio_id
 * @property int|null $signal_id
 * @property int $asset_id
 * @property string $broker
 * @property string|null $external_id
 * @property OrderSide $side
 * @property OrderType $type
 * @property float $qty
 * @property float|null $price
 * @property float|null $stop_price
 * @property OrderStatus $status
 * @property float $fees
 * @property string|null $reject_reason
 * @property CarbonImmutable|null $filled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Portfolio $portfolio
 * @property-read Signal $signal
 * @property-read Asset $asset
 * @property-read Collection<int, Fill> $fills
 */
#[Fillable(['portfolio_id', 'signal_id', 'asset_id', 'broker', 'external_id', 'side', 'type', 'qty', 'price', 'stop_price', 'status', 'fees', 'reject_reason', 'filled_at'])]
class Order extends Model
{
    /**
     * @return array<string, string>
     */
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

    /** @return BelongsTo<Portfolio, $this> */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /** @return BelongsTo<Signal, $this> */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return HasMany<Fill, $this> */
    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class);
    }
}
