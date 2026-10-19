<?php

namespace App\Models;

use App\Enums\MicroRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One micro operation: a small buy, the planned exit (target or stop) and, once
 * closed, the fees and the net profit of the round trip.
 *
 * @property int $id
 * @property int $portfolio_id
 * @property int $asset_id
 * @property int|null $position_id
 * @property string $status "pending" (limit buy waiting), "open" or "closed"
 * @property float $qty
 * @property float $entry_price
 * @property float $target_price
 * @property float $stop_price
 * @property float|null $exit_price
 * @property float $fees
 * @property float|null $net_pnl
 * @property float $gross_margin_pct
 * @property MicroRule $rule
 * @property int|null $max_hold_minutes
 * @property string|null $exit_reason
 * @property array<string, mixed>|null $analysis
 * @property float|null $suggested_price the engine's suggested buy price (the limit, or the reference of a market buy)
 * @property int|null $buy_order_id
 * @property CarbonImmutable|null $expires_at when a pending limit buy is cancelled
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property-read Portfolio $portfolio
 * @property-read Asset $asset
 * @property-read Position|null $position
 * @property-read Order|null $buyOrder
 */
#[Fillable(['portfolio_id', 'asset_id', 'position_id', 'status', 'qty', 'entry_price', 'target_price', 'stop_price', 'exit_price', 'fees', 'net_pnl', 'gross_margin_pct', 'rule', 'max_hold_minutes', 'exit_reason', 'analysis', 'suggested_price', 'buy_order_id', 'expires_at', 'opened_at', 'closed_at'])]
class MicroCycle extends Model
{
    /**
     * Same default as the column, so a model created in memory already has its rule.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'rule' => 'scalp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'entry_price' => 'float',
            'target_price' => 'float',
            'stop_price' => 'float',
            'exit_price' => 'float',
            'fees' => 'float',
            'net_pnl' => 'float',
            'gross_margin_pct' => 'float',
            'rule' => MicroRule::class,
            'max_hold_minutes' => 'integer',
            'analysis' => 'array',
            'suggested_price' => 'float',
            'expires_at' => 'datetime',
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

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function buyOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'buy_order_id');
    }
}
