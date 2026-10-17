<?php

namespace App\Agents\Consensus;

use App\Agents\Data\Vote;
use App\Enums\OrderSide;
use App\Models\RiskProfile;

/**
 * Aggregates the voters' answers as described in the spec:
 *
 * 1. Each vote becomes a value in [-1, 1] (buy = +confidence, sell = -confidence, hold = 0).
 *    Abstentions (agent without data) are left out entirely; a real hold still counts.
 * 2. The score is the weighted mean of those values.
 * 3. Approval needs at least `$minVoters` valid votes, |score| >= threshold and at least N voters pointing the same way,
 *    where N is the profile's minimum capped at the number of valid votes (a panel of 3 asks for 3, never for an impossible 4).
 * 4. A veto from the risk agent rejects the operation regardless of the score.
 */
class ConsensusEngine
{
    /**
     * @param  list<Vote>  $votes
     * @param  array<string, float>  $weights  Keyed by AgentRole value; missing roles weigh 1.
     * @param  int  $minVoters  Valid (non-abstained) votes needed before the signal can be approved.
     */
    public function decide(array $votes, RiskProfile $profile, array $weights = [], int $minVoters = 3): ConsensusResult
    {
        if ($votes === []) {
            return new ConsensusResult(false, null, 0, 0, false, 'Nenhum voto.');
        }

        $all = $votes;
        $votes = array_values(array_filter($votes, fn (Vote $vote) => ! $vote->abstained));
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
        $quorum = min($profile->min_agreeing_voters, count($votes));

        // The worksheet shown to the user: every number the verdict came from, stored with the signal.
        $calculation = [
            'votes' => array_map(fn (Vote $vote) => [
                'agent' => $vote->agent->value,
                'action' => $vote->action->value,
                'confidence' => $vote->confidence,
                'abstained' => $vote->abstained,
                'veto' => $vote->veto && $vote->agent->canVeto(),
                'weight' => $vote->abstained ? null : ($weights[$vote->agent->value] ?? 1.0),
                'contribution' => $vote->abstained || $weightSum <= 0 ? null : round(($weights[$vote->agent->value] ?? 1.0) * $vote->value() / $weightSum, 4),
                'reason' => $vote->reasons[0] ?? null,
            ], $all),
            'valid_votes' => count($votes),
            'weight_sum' => round($weightSum, 4),
            'score' => $score,
            'threshold' => (float) $profile->consensus_threshold,
            'min_voters' => $minVoters,
            'agreeing' => $agreeing,
            'quorum' => $quorum,
        ];

        $decided = fn (bool $approved, bool $vetoed, string $reason) => new ConsensusResult($approved, $side, $score, $agreeing, $vetoed, $reason, $calculation);

        $veto = collect($votes)->first(fn (Vote $vote) => $vote->veto && $vote->agent->canVeto());

        if ($veto !== null) {
            return $decided(false, true, 'Veto do agente de risco: '.implode(' ', $veto->reasons));
        }

        if (count($votes) < $minVoters) {
            return $decided(false, false, sprintf('Só %d %s: mínimo de %d.', count($votes), count($votes) === 1 ? 'agente votou' : 'agentes votaram', $minVoters));
        }

        if (abs($score) < $profile->consensus_threshold) {
            return $decided(false, false, sprintf('Score %.2f abaixo do limite %.2f.', $score, $profile->consensus_threshold));
        }

        if ($agreeing < $quorum) {
            return $decided(false, false, sprintf('Só %d de %d votantes concordam (mínimo %d).', $agreeing, count($votes), $quorum));
        }

        return $decided(true, false, sprintf('Aprovado com score %.2f e %d votos na mesma direção.', $score, $agreeing));
    }
}
