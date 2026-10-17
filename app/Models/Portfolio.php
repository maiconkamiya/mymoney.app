<?php

namespace App\Models;

use App\Enums\Market;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PortfolioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A wallet that trades a starting amount (X) over a period (Y), simulated or live.
 *
 * @property int $id
 * @property int $user_id
 * @property int $risk_profile_id
 * @property int|null $broker_credential_id
 * @property string $name
 * @property Market $market
 * @property PortfolioMode $mode
 * @property string $broker
 * @property PortfolioStatus $status
 * @property float $initial_cash
 * @property float $cash
 * @property string|null $benchmark
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property bool $auto_execute
 * @property CarbonImmutable|null $synced_at
 * @property array{holdings?: list<array{coin: string, qty: float, price: float|null, value: float|null, status: string, reason: string|null}>}|null $sync_report
 * @property CarbonImmutable|null $live_auto_confirmed_at
 * @property CarbonImmutable|null $orders_blocked_at
 * @property string|null $orders_blocked_reason
 * @property bool $notify_live_email
 * @property bool $micro_enabled
 * @property float $micro_order_value
 * @property float $micro_net_margin_pct
 * @property float|null $micro_daily_target
 * @property float|null $micro_daily_loss_limit
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read RiskProfile $riskProfile
 * @property-read BrokerCredential|null $brokerCredential
 * @property-read Collection<int, Position> $positions
 * @property-read Collection<int, Position> $openPositions
 * @property-read Collection<int, Order> $orders
 * @property-read Collection<int, Signal> $signals
 * @property-read Collection<int, PortfolioSnapshot> $snapshots
 * @property-read Collection<int, MicroCycle> $microCycles
 */
#[Fillable(['user_id', 'risk_profile_id', 'broker_credential_id', 'name', 'market', 'mode', 'broker', 'status', 'initial_cash', 'cash', 'benchmark', 'starts_at', 'ends_at', 'auto_execute', 'live_auto_confirmed_at', 'orders_blocked_at', 'orders_blocked_reason', 'notify_live_email', 'synced_at', 'sync_report', 'micro_enabled', 'micro_order_value', 'micro_net_margin_pct', 'micro_daily_target', 'micro_daily_loss_limit'])]
class Portfolio extends Model
{
    /** @use HasFactory<PortfolioFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'mode' => PortfolioMode::class,
            'status' => PortfolioStatus::class,
            'initial_cash' => 'float',
            'cash' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'auto_execute' => 'boolean',
            'synced_at' => 'datetime',
            'sync_report' => 'array',
            'live_auto_confirmed_at' => 'datetime',
            'orders_blocked_at' => 'datetime',
            'notify_live_email' => 'boolean',
            'micro_enabled' => 'boolean',
            'micro_order_value' => 'float',
            'micro_net_margin_pct' => 'float',
            'micro_daily_target' => 'float',
            'micro_daily_loss_limit' => 'float',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RiskProfile, $this> */
    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }

    /** @return BelongsTo<BrokerCredential, $this> */
    public function brokerCredential(): BelongsTo
    {
        return $this->belongsTo(BrokerCredential::class);
    }

    /** @return HasMany<Position, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /** @return HasMany<Position, $this> */
    public function openPositions(): HasMany
    {
        return $this->positions()->whereNull('closed_at');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Signal, $this> */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /** @return HasMany<PortfolioSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
    }

    /** @return HasMany<MicroCycle, $this> */
    public function microCycles(): HasMany
    {
        return $this->hasMany(MicroCycle::class);
    }

    public function isLive(): bool
    {
        return $this->mode === PortfolioMode::Live;
    }

    /**
     * Real money moves without a person's approval only after the owner typed
     * the confirmation, and never while "Parar tudo" is in force.
     */
    public function autoExecutesLive(): bool
    {
        return $this->isLive() && $this->auto_execute && $this->live_auto_confirmed_at !== null && ! $this->isOrdersBlocked();
    }

    public function isOrdersBlocked(): bool
    {
        return $this->orders_blocked_at !== null;
    }

    public function isRunning(): bool
    {
        return $this->status === PortfolioStatus::Running;
    }
}
