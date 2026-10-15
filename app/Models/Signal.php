<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\SignalStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $portfolio_id
 * @property int $asset_id
 * @property SignalStage $stage
 * @property OrderSide|null $side
 * @property float|null $score
 * @property float|null $entry_price
 * @property float|null $stop_price
 * @property float|null $target_price
 * @property string|null $decided_by
 * @property string|null $decision_reason
 * @property array<string, mixed>|null $scenario
 * @property array<int, array{stage: string, at: string}>|null $stage_history
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Portfolio $portfolio
 * @property-read Asset $asset
 * @property-read Collection<int, AgentVote> $votes
 * @property-read Collection<int, Order> $orders
 */
#[Fillable(['portfolio_id', 'asset_id', 'stage', 'side', 'score', 'entry_price', 'stop_price', 'target_price', 'decided_by', 'decision_reason', 'scenario', 'stage_history'])]
class Signal extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => SignalStage::class,
            'side' => OrderSide::class,
            'score' => 'float',
            'entry_price' => 'float',
            'stop_price' => 'float',
            'target_price' => 'float',
            'scenario' => 'array',
            'stage_history' => 'array',
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

    /** @return HasMany<AgentVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(AgentVote::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Move the signal to a new funnel stage and record when it happened.
     */
    public function moveTo(SignalStage $stage, ?string $reason = null): void
    {
        $history = $this->stage_history ?? [];
        $history[] = ['stage' => $stage->value, 'at' => now()->toIso8601String()];

        $this->forceFill([
            'stage' => $stage,
            'stage_history' => $history,
            'decision_reason' => $reason ?? $this->decision_reason,
        ])->save();
    }
}
