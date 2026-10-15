<?php

namespace App\Models;

use App\Enums\AgentRole;
use App\Enums\VoteAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['signal_id', 'agent', 'round', 'action', 'confidence', 'veto', 'reasons', 'evidence_ids', 'stop_price', 'target_price', 'model', 'input_tokens', 'output_tokens', 'cost'])]
class AgentVote extends Model
{
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
            'cost' => 'float',
        ];
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
