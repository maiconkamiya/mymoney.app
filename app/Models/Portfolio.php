<?php

namespace App\Models;

use App\Enums\Market;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PortfolioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 */
#[Fillable(['user_id', 'risk_profile_id', 'broker_credential_id', 'name', 'market', 'mode', 'broker', 'status', 'initial_cash', 'cash', 'benchmark', 'starts_at', 'ends_at', 'auto_execute'])]
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

    /** @return BelongsToMany<Asset, $this> */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'portfolio_asset')->withTimestamps();
    }

    /**
     * Carteiras que acompanham o ativo: as que o escolheram e as que não escolheram nenhum (acompanham todos).
     *
     * @param  Builder<Portfolio>  $query
     */
    #[Scope]
    protected function tracking(Builder $query, Asset $asset): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('assets')
            ->orWhereHas('assets', fn (Builder $a) => $a->whereKey($asset->id)));
    }

    public function isLive(): bool
    {
        return $this->mode === PortfolioMode::Live;
    }

    public function isRunning(): bool
    {
        return $this->status === PortfolioStatus::Running;
    }
}
