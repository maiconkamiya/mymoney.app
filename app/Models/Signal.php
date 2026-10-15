<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\SignalStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property SignalStage $stage
 * @property OrderSide|null $side
 * @property float|null $score
 * @property float|null $entry_price
 * @property float|null $stop_price
 * @property float|null $target_price
 * @property array<int, array{stage: string, at: string}>|null $stage_history
 * @property Portfolio $portfolio
 * @property Asset $asset
 */
#[Fillable(['portfolio_id', 'asset_id', 'stage', 'side', 'score', 'entry_price', 'stop_price', 'target_price', 'decided_by', 'decision_reason', 'scenario', 'stage_history'])]
class Signal extends Model
{
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

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AgentVote::class);
    }

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
