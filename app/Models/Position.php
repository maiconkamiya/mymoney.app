<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $portfolio_id
 * @property int $asset_id
 * @property int|null $signal_id
 * @property float $qty
 * @property float $avg_price
 * @property float|null $stop_price
 * @property float|null $target_price
 * @property float $realized_pnl
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $exit_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Portfolio $portfolio
 * @property-read Asset $asset
 * @property-read Signal|null $signal
 */
#[Fillable(['portfolio_id', 'asset_id', 'signal_id', 'qty', 'avg_price', 'stop_price', 'target_price', 'realized_pnl', 'opened_at', 'closed_at', 'exit_reason'])]
class Position extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'avg_price' => 'float',
            'stop_price' => 'float',
            'target_price' => 'float',
            'realized_pnl' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Portfolio, $this> */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Signal, $this> */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
