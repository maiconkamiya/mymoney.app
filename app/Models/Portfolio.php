<?php

namespace App\Models;

use App\Enums\Market;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A wallet that trades a starting amount (X) over a period (Y), simulated or live.
 *
 * @property int $id
 * @property string $name
 * @property Market $market
 * @property PortfolioMode $mode
 * @property PortfolioStatus $status
 * @property string $broker
 * @property float $initial_cash
 * @property float $cash
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $auto_execute
 * @property RiskProfile $riskProfile
 */
#[Fillable(['user_id', 'risk_profile_id', 'broker_credential_id', 'name', 'market', 'mode', 'broker', 'status', 'initial_cash', 'cash', 'benchmark', 'starts_at', 'ends_at', 'auto_execute'])]
class Portfolio extends Model
{
    use HasFactory;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }

    public function brokerCredential(): BelongsTo
    {
        return $this->belongsTo(BrokerCredential::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function openPositions(): HasMany
    {
        return $this->positions()->whereNull('closed_at');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
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
