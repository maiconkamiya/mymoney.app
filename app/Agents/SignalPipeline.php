<?php

namespace App\Agents;

use App\Agents\Consensus\ConsensusEngine;
use App\Agents\Consensus\ConsensusResult;
use App\Agents\Consensus\LateEntryCheck;
use App\Agents\Data\Scenario;
use App\Agents\Data\Vote;
use App\Agents\Llm\LlmClient;
use App\Enums\AgentRole;
use App\Enums\OrderSide;
use App\Enums\SignalStage;
use App\Models\AgentScore;
use App\Models\Signal;
use App\Trading\OrderExecutor;
use Illuminate\Support\Facades\Cache;

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
        private readonly LateEntryCheck $lateEntry,
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
        $firstRound = [];

        foreach ($voters as $role) {
            $firstRound[] = $this->skipsRisk($role, $firstRound)
                ? $this->announce($signal, Vote::abstain($role, 'Sem direção na primeira rodada: o Risco não foi consultado para poupar custo.'), 1)
                : $this->cast($signal, $role, 1, $scenario);
        }

        $this->store($signal, $firstRound, 1);

        $finalVotes = $firstRound;
        $lateBeforeSkeptic = $this->lateEntryBeforeSkeptic($firstRound, $scenario);

        // The challenge round only matters when the first round points somewhere, and is
        // skipped (when switched on) if the late-entry rule would reject the buy anyway.
        if ($lateBeforeSkeptic === null && array_filter($firstRound, fn (Vote $vote) => $vote->action->direction() !== 0) !== []) {
            $skeptic = $this->cast($signal, AgentRole::Skeptic, 2, $scenario, $firstRound);
            $objection = implode(' ', $skeptic->reasons);

            $finalVotes = array_map(fn (AgentRole $role) => $this->cast($signal, $role, 2, $scenario, $firstRound, $objection), $voters);
            $finalVotes[] = $skeptic;
            $this->store($signal, $finalVotes, 2);
        }

        $result = $this->consensus->decide($finalVotes, $signal->portfolio->riskProfile, $this->weights($signal), (int) config('trading.consensus.min_voters'));

        if ($lateBeforeSkeptic !== null) {
            $result = new ConsensusResult(
                false,
                OrderSide::Buy,
                $result->score,
                $result->agreeing,
                false,
                (string) $lateBeforeSkeptic['reason'].' O Cético não foi consultado.',
                [...($result->calculation ?? []), 'late_entry' => [...$lateBeforeSkeptic['details'], 'blocked' => true], 'skeptic_skipped' => true],
            );
        }

        $stop = $this->median($finalVotes, $result, 'stop');
        $result = $lateBeforeSkeptic === null ? $this->checkLateEntry($result, $scenario, $stop) : $result;

        $signal->update([
            'score' => $result->score,
            'calculation' => $result->calculation,
            'side' => $result->side,
            'stop_price' => $stop,
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

        // The agents take a while: reread the portfolio so a "Parar tudo" pressed meanwhile counts.
        $portfolio = $signal->portfolio->refresh();

        // Real money runs on its own only when the owner confirmed it (CONFIRMO) and nothing blocks the portfolio.
        if ($result->approved && $portfolio->auto_execute && (! $portfolio->isLive() || $portfolio->autoExecutesLive())) {
            $signal->update(['decided_by' => 'auto']);
            $signal->moveTo(SignalStage::Confirmed);
            $this->executor->execute($signal, 'auto');
        }

        return $result;
    }

    /**
     * A buy also has to pass the late-entry rule: room up to the probable
     * ceiling against the risk, and distance from the 21-period EMA. Its
     * numbers go to the worksheet whenever the vote points to a buy.
     */
    private function checkLateEntry(ConsensusResult $result, Scenario $scenario, ?float $stop): ConsensusResult
    {
        if ($result->side !== OrderSide::Buy) {
            return $result;
        }

        $check = $this->lateEntry->check($scenario->chart['extension'] ?? null, $scenario->price, $stop);

        if ($check === null) {
            return $result;
        }

        $calculation = [...($result->calculation ?? []), 'late_entry' => [...$check['details'], 'blocked' => $check['blocked']]];

        return $result->approved && $check['blocked']
            ? new ConsensusResult(false, $result->side, $result->score, $result->agreeing, false, (string) $check['reason'], $calculation)
            : new ConsensusResult($result->approved, $result->side, $result->score, $result->agreeing, $result->vetoed, $result->reason, $calculation);
    }

    /**
     * With the switch on, the Risk agent is not asked when nobody before it in the
     * first round points anywhere: without a direction no signal can be approved.
     *
     * @param  list<Vote>  $before
     */
    private function skipsRisk(AgentRole $role, array $before): bool
    {
        return $role === AgentRole::Risk
            && config('agents.risk_needs_direction')
            && array_filter($before, fn (Vote $vote) => $vote->action->direction() !== 0) === [];
    }

    /**
     * With the switch on, the late-entry rule runs before the challenge round: a
     * first round leaning to buy that the rule would block gets its rejection now,
     * without the skeptic's call. Returns the check when it blocks, else null.
     *
     * @param  list<Vote>  $firstRound
     * @return array{blocked: bool, reason: string|null, details: array<string, mixed>}|null
     */
    private function lateEntryBeforeSkeptic(array $firstRound, Scenario $scenario): ?array
    {
        if (! config('agents.skip_skeptic_on_late_entry')) {
            return null;
        }

        $buys = array_filter($firstRound, fn (Vote $vote) => $vote->action->direction() === 1);

        if ($buys === [] || array_sum(array_map(fn (Vote $vote) => $vote->value(), $firstRound)) <= 0) {
            return null;
        }

        $stops = collect($buys)->pluck('stop')->filter()->sort()->values();
        $check = $this->lateEntry->check($scenario->chart['extension'] ?? null, $scenario->price, $stops->isEmpty() ? null : (float) $stops->median());

        return $check !== null && $check['blocked'] ? $check : null;
    }

    /**
     * A vote decided without the model is announced like any other.
     */
    private function announce(Signal $signal, Vote $vote, int $round): Vote
    {
        $this->broadcaster->agentStarted($signal, $vote->agent, $round);
        $this->broadcaster->agentVoted($signal, $vote, $round);

        return $vote;
    }

    /**
     * One agent consults its model and answers; the start and the answer are announced as they happen.
     *
     * @param  list<Vote>  $priorVotes
     */
    private function cast(Signal $signal, AgentRole $role, int $round, Scenario $scenario, array $priorVotes = [], ?string $objection = null): Vote
    {
        $this->broadcaster->agentStarted($signal, $role, $round);
        $vote = $this->reusableNewsVote($signal, $role, $scenario)
            ?? $this->agent($role)->vote($scenario, $priorVotes, $objection);
        $this->rememberNewsVote($signal, $role, $scenario, $vote);
        $this->broadcaster->agentVoted($signal, $vote, $round);

        return $vote;
    }

    /**
     * The News vote is recomputed only when new news reaches the asset or the
     * interval runs out; in between the last vote is reused without a model call.
     */
    private function reusableNewsVote(Signal $signal, AgentRole $role, Scenario $scenario): ?Vote
    {
        $key = $this->newsVoteKey($signal, $role, $scenario);
        $cached = $key !== null ? Cache::get($key) : null;

        if (! is_string($cached)) {
            return null;
        }

        $vote = unserialize($cached, ['allowed_classes' => true]);

        if (! $vote instanceof Vote) {
            return null;
        }

        // Reused: no tokens and no cost this time.
        return new Vote(
            agent: $vote->agent,
            action: $vote->action,
            confidence: $vote->confidence,
            veto: $vote->veto,
            reasons: $vote->reasons,
            evidenceIds: $vote->evidenceIds,
            stop: $vote->stop,
            target: $vote->target,
            model: $vote->model,
        );
    }

    private function rememberNewsVote(Signal $signal, AgentRole $role, Scenario $scenario, Vote $vote): void
    {
        $key = $this->newsVoteKey($signal, $role, $scenario);

        // An abstention (no news, model failure) is never kept: it costs nothing and must be retried.
        if ($key === null || $vote->abstained) {
            return;
        }

        Cache::put($key, serialize($vote), now()->addMinutes((int) config('agents.news.interval_minutes')));
    }

    /**
     * Cache key for the News vote of this asset, tied to the news the agent would read.
     * Null when it is not the News agent or the reuse is off (interval 0).
     */
    private function newsVoteKey(Signal $signal, AgentRole $role, Scenario $scenario): ?string
    {
        if ($role !== AgentRole::News || (int) config('agents.news.interval_minutes') <= 0) {
            return null;
        }

        $ids = array_merge(array_column($scenario->news, 'id'), array_column($scenario->similarNews, 'id'));
        sort($ids);

        return 'agents:news-vote:'.$signal->asset_id.':'.md5(implode(',', $ids));
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
                'analysis' => $vote->analysis,
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
