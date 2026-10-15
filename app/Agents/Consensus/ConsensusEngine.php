<?php

namespace App\Agents\Consensus;

use App\Agents\Data\Vote;
use App\Enums\OrderSide;
use App\Models\RiskProfile;

/**
 * Aggregates the voters' answers as described in the spec:
 *
 * 1. Each vote becomes a value in [-1, 1] (buy = +confidence, sell = -confidence, hold = 0).
 * 2. The score is the weighted mean of those values.
 * 3. Approval needs |score| >= threshold and at least N voters pointing the same way.
 * 4. A veto from the risk agent rejects the operation regardless of the score.
 */
class ConsensusEngine
{
    /**
     * @param  list<Vote>  $votes
     * @param  array<string, float>  $weights  Keyed by AgentRole value; missing roles weigh 1.
     */
    public function decide(array $votes, RiskProfile $profile, array $weights = []): ConsensusResult
    {
        if ($votes === []) {
            return new ConsensusResult(false, null, 0, 0, false, 'Nenhum voto.');
        }

        $weightSum = 0.0;
        $weighted = 0.0;

        foreach ($votes as $vote) {
            $weight = $weights[$vote->agent->value] ?? 1.0;
            $weightSum += $weight;
            $weighted += $weight * $vote->value();
        }

        $score = $weightSum > 0 ? round($weighted / $weightSum, 4) : 0.0;
        $direction = $score <=> 0;
        $side = match ($direction) {
            1 => OrderSide::Buy,
            -1 => OrderSide::Sell,
            default => null,
        };

        $agreeing = count(array_filter($votes, fn (Vote $vote) => $direction !== 0 && $vote->action->direction() === $direction));

        $veto = collect($votes)->first(fn (Vote $vote) => $vote->veto && $vote->agent->canVeto());

        if ($veto !== null) {
            return new ConsensusResult(false, $side, $score, $agreeing, true, 'Veto do agente de risco: '.implode(' ', $veto->reasons));
        }

        if (abs($score) < $profile->consensus_threshold) {
            return new ConsensusResult(false, $side, $score, $agreeing, false, sprintf('Score %.2f abaixo do limite %.2f.', $score, $profile->consensus_threshold));
        }

        if ($agreeing < $profile->min_agreeing_voters) {
            return new ConsensusResult(false, $side, $score, $agreeing, false, sprintf('Só %d de %d votantes concordam (mínimo %d).', $agreeing, count($votes), $profile->min_agreeing_voters));
        }

        return new ConsensusResult(true, $side, $score, $agreeing, false, sprintf('Aprovado com score %.2f e %d votos na mesma direção.', $score, $agreeing));
    }
}
