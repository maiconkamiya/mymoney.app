<?php

namespace App\Models;

use App\Enums\AgentRole;
use App\Enums\VoteAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $signal_id
 * @property AgentRole $agent
 * @property int $round
 * @property VoteAction $action
 * @property float $confidence
 * @property bool $veto
 * @property list<string>|null $reasons
 * @property list<string>|null $evidence_ids
 * @property float|null $stop_price
 * @property float|null $target_price
 * @property array<string, mixed>|null $analysis
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Signal $signal
 */
#[Fillable(['signal_id', 'agent', 'round', 'action', 'confidence', 'veto', 'reasons', 'evidence_ids', 'stop_price', 'target_price', 'analysis', 'model', 'input_tokens', 'output_tokens', 'cost'])]
class AgentVote extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agent' => AgentRole::class,
            'action' => VoteAction::class,
            'confidence' => 'float',
            'veto' => 'boolean',
            'reasons' => 'array',
            'evidence_ids' => 'array',
            'stop_price' => 'float',
            'target_price' => 'float',
            'analysis' => 'array',
            'cost' => 'float',
        ];
    }

    /** @return BelongsTo<Signal, $this> */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
