<?php

namespace App\Trading;

use App\Models\AgentVote;
use App\Models\Signal;

/**
 * The shape of a signal on the portfolio pages: the latest-signals list and
 * the full history render the same card, so both read it from here.
 */
class SignalPresenter
{
    /**
     * The signal must come with its asset and votes loaded.
     *
     * @return array<string, mixed>
     */
    public static function present(Signal $signal): array
    {
        return [
            'id' => $signal->id,
            'symbol' => $signal->asset->symbol,
            'stage' => $signal->stage->value,
            'side' => $signal->side?->value,
            'score' => $signal->score,
            'calculation' => $signal->calculation,
            'entry_price' => $signal->entry_price,
            'stop_price' => $signal->stop_price,
            'target_price' => $signal->target_price,
            'reason' => $signal->decision_reason,
            'created_at' => $signal->created_at->toIso8601String(),
            'cost' => round($signal->votes->sum('cost'), 4),
            'votes' => $signal->votes->sortBy([['round', 'desc']])->unique(fn (AgentVote $vote) => $vote->agent->value)->values()->map(fn (AgentVote $vote) => [
                'agent' => $vote->agent->value,
                'round' => $vote->round,
                'action' => $vote->action->value,
                'confidence' => $vote->confidence,
                'veto' => $vote->veto,
                'abstained' => $vote->abstained,
                'reasons' => $vote->reasons ?? [],
            ])->all(),
        ];
    }
}
