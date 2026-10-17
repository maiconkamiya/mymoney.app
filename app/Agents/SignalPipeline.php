<?php

namespace App\Agents;

use App\Agents\Consensus\ConsensusEngine;
use App\Agents\Consensus\ConsensusResult;
use App\Agents\Data\Scenario;
use App\Agents\Data\Vote;
use App\Agents\Llm\LlmClient;
use App\Enums\AgentRole;
use App\Enums\OrderSide;
use App\Enums\SignalStage;
use App\Models\AgentScore;
use App\Models\Signal;
use App\Trading\OrderExecutor;

/**
 * The coordinator: builds one scenario, runs the vote and the skeptic's
 * challenge round, computes the consensus and, when the portfolio allows it,
 * hands the approved signal to the executor.
 */
class SignalPipeline
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ScenarioBuilder $scenarios,
        private readonly ConsensusEngine $consensus,
        private readonly OrderExecutor $executor,
        private readonly AgentActivityBroadcaster $broadcaster,
    ) {}

    public function analyze(Signal $signal): ?ConsensusResult
    {
        if ($signal->stage !== SignalStage::Scanned) {
            return null;
        }

        $this->broadcaster->cycleStarted($signal);
        $scenario = $this->scenarios->build($signal);

        if ($scenario === null) {
            $signal->moveTo(SignalStage::Rejected, 'Sem cotação para montar o cenário.');
            $this->broadcaster->consensusDecided($signal);

            return null;
        }

        $signal->update(['scenario' => $scenario->toArray(), 'entry_price' => $scenario->price]);

        $voters = array_values(array_filter(AgentRole::voters(), fn (AgentRole $role) => $role !== AgentRole::Skeptic));
        $firstRound = array_map(fn (AgentRole $role) => $this->cast($signal, $role, 1, $scenario), $voters);
        $this->store($signal, $firstRound, 1);

        $finalVotes = $firstRound;

        // The challenge round only matters when the first round points somewhere.
        if (array_filter($firstRound, fn (Vote $vote) => $vote->action->direction() !== 0) !== []) {
            $skeptic = $this->cast($signal, AgentRole::Skeptic, 2, $scenario, $firstRound);
            $objection = implode(' ', $skeptic->reasons);

            $finalVotes = array_map(fn (AgentRole $role) => $this->cast($signal, $role, 2, $scenario, $firstRound, $objection), $voters);
            $finalVotes[] = $skeptic;
            $this->store($signal, $finalVotes, 2);
        }

        $result = $this->consensus->decide($finalVotes, $signal->portfolio->riskProfile, $this->weights($signal), (int) config('trading.consensus.min_voters'));

        $signal->update([
            'score' => $result->score,
            'side' => $result->side,
            'stop_price' => $this->median($finalVotes, $result, 'stop'),
            'target_price' => $this->median($finalVotes, $result, 'target'),
        ]);
        $signal->moveTo(SignalStage::Analyzed);

        if ($result->approved && $result->side === OrderSide::Sell && ! $signal->portfolio->openPositions()->where('asset_id', $signal->asset_id)->exists()) {
            $signal->moveTo(SignalStage::Rejected, 'Venda aprovada, mas não há posição aberta para vender.');
            $this->broadcaster->consensusDecided($signal);

            return $result;
        }

        $signal->moveTo($result->approved ? SignalStage::Approved : SignalStage::Rejected, $result->reason);
        $this->broadcaster->consensusDecided($signal);

        if ($result->approved && $signal->portfolio->auto_execute && ! $signal->portfolio->isLive()) {
            $signal->update(['decided_by' => 'auto']);
            $signal->moveTo(SignalStage::Confirmed);
            $this->executor->execute($signal, 'auto');
        }

        return $result;
    }

    /**
     * One agent consults its model and answers; the start and the answer are announced as they happen.
     *
     * @param  list<Vote>  $priorVotes
     */
    private function cast(Signal $signal, AgentRole $role, int $round, Scenario $scenario, array $priorVotes = [], ?string $objection = null): Vote
    {
        $this->broadcaster->agentStarted($signal, $role, $round);
        $vote = $this->agent($role)->vote($scenario, $priorVotes, $objection);
        $this->broadcaster->agentVoted($signal, $vote, $round);

        return $vote;
    }

    private function agent(AgentRole $role): LlmAgent
    {
        return new LlmAgent($role, $this->llm);
    }

    /**
     * @param  list<Vote>  $votes
     */
    private function store(Signal $signal, array $votes, int $round): void
    {
        foreach ($votes as $vote) {
            $signal->votes()->create([
                'agent' => $vote->agent,
                'round' => $round,
                'action' => $vote->action,
                'confidence' => $vote->confidence,
                'veto' => $vote->veto,
                'abstained' => $vote->abstained,
                'reasons' => $vote->reasons,
                'evidence_ids' => $vote->evidenceIds,
                'stop_price' => $vote->stop,
                'target_price' => $vote->target,
                'model' => $vote->model,
                'input_tokens' => $vote->inputTokens,
                'output_tokens' => $vote->outputTokens,
                'cost' => $vote->cost,
            ]);
        }
    }

    /**
     * @return array<string, float>
     */
    private function weights(Signal $signal): array
    {
        return AgentScore::query()
            ->where('market', $signal->asset->market)
            ->pluck('weight', 'agent')
            ->map(fn ($weight) => (float) $weight)
            ->all();
    }

    /**
     * Median stop or target among the votes that agree with the decision.
     *
     * @param  list<Vote>  $votes
     */
    private function median(array $votes, ConsensusResult $result, string $field): ?float
    {
        if ($result->side === null) {
            return null;
        }

        $values = collect($votes)
            ->filter(fn (Vote $vote) => $vote->action->direction() === ($result->score <=> 0))
            ->pluck($field)
            ->filter()
            ->sort()
            ->values();

        return $values->isEmpty() ? null : (float) $values->median();
    }
}
