<?php

namespace Tests\Unit;

use App\Agents\Consensus\ConsensusEngine;
use App\Agents\Data\Vote;
use App\Enums\AgentRole;
use App\Enums\OrderSide;
use App\Enums\VoteAction;
use App\Models\RiskProfile;
use PHPUnit\Framework\TestCase;

class ConsensusEngineTest extends TestCase
{
    private function profile(): RiskProfile
    {
        return (new RiskProfile)->forceFill(['consensus_threshold' => 0.35, 'min_agreeing_voters' => 4]);
    }

    /**
     * @param  array<string, array{0: VoteAction, 1: float}>  $votes
     * @return list<Vote>
     */
    private function votes(array $votes, bool $riskVeto = false): array
    {
        return array_map(
            fn (string $role, array $vote) => new Vote(AgentRole::from($role), $vote[0], $vote[1], veto: $riskVeto && $role === 'risk', reasons: ['motivo']),
            array_keys($votes),
            $votes,
        );
    }

    public function test_strong_agreement_is_approved()
    {
        $result = (new ConsensusEngine)->decide($this->votes([
            'technical' => [VoteAction::Buy, 0.8],
            'news' => [VoteAction::Buy, 0.7],
            'fundamentals' => [VoteAction::Buy, 0.5],
            'quant' => [VoteAction::Buy, 0.6],
            'skeptic' => [VoteAction::Hold, 0],
            'risk' => [VoteAction::Buy, 0.4],
        ]), $this->profile());

        $this->assertTrue($result->approved);
        $this->assertSame(OrderSide::Buy, $result->side);
        $this->assertSame(5, $result->agreeing);
        $this->assertEqualsWithDelta(0.5, $result->score, 0.0001);
    }

    public function test_weak_score_is_rejected()
    {
        $result = (new ConsensusEngine)->decide($this->votes([
            'technical' => [VoteAction::Buy, 0.3],
            'news' => [VoteAction::Buy, 0.3],
            'fundamentals' => [VoteAction::Buy, 0.3],
            'quant' => [VoteAction::Buy, 0.3],
            'skeptic' => [VoteAction::Sell, 0.5],
            'risk' => [VoteAction::Hold, 0],
        ]), $this->profile());

        $this->assertFalse($result->approved);
        $this->assertStringContainsString('abaixo do limite', $result->reason);
    }

    public function test_too_few_agreeing_voters_is_rejected()
    {
        $result = (new ConsensusEngine)->decide($this->votes([
            'technical' => [VoteAction::Buy, 1],
            'news' => [VoteAction::Buy, 1],
            'fundamentals' => [VoteAction::Buy, 1],
            'quant' => [VoteAction::Hold, 0],
            'skeptic' => [VoteAction::Hold, 0],
            'risk' => [VoteAction::Hold, 0],
        ]), $this->profile());

        $this->assertFalse($result->approved);
        $this->assertSame(3, $result->agreeing);
    }

    public function test_risk_veto_overrides_a_unanimous_score()
    {
        $result = (new ConsensusEngine)->decide($this->votes([
            'technical' => [VoteAction::Buy, 1],
            'news' => [VoteAction::Buy, 1],
            'fundamentals' => [VoteAction::Buy, 1],
            'quant' => [VoteAction::Buy, 1],
            'skeptic' => [VoteAction::Buy, 1],
            'risk' => [VoteAction::Buy, 1],
        ], riskVeto: true), $this->profile());

        $this->assertFalse($result->approved);
        $this->assertTrue($result->vetoed);
    }

    public function test_weights_shift_the_score()
    {
        $votes = $this->votes([
            'technical' => [VoteAction::Buy, 1],
            'news' => [VoteAction::Sell, 1],
        ]);

        $result = (new ConsensusEngine)->decide($votes, $this->profile(), ['technical' => 3.0, 'news' => 1.0]);

        $this->assertEqualsWithDelta(0.5, $result->score, 0.0001);
    }

    /**
     * @return list<Vote>
     */
    private function withAbstentions(): array
    {
        return [
            new Vote(AgentRole::Technical, VoteAction::Buy, 0.8, reasons: ['a']),
            new Vote(AgentRole::Skeptic, VoteAction::Buy, 0.6, reasons: ['b']),
            new Vote(AgentRole::Risk, VoteAction::Buy, 0.7, reasons: ['c']),
            Vote::abstain(AgentRole::Quant, 'Previsão indisponível.'),
            Vote::abstain(AgentRole::News, 'Nenhuma notícia relevante.'),
            Vote::abstain(AgentRole::Fundamentals, 'Sem fundamentos.'),
        ];
    }

    public function test_abstentions_do_not_change_the_average()
    {
        $result = (new ConsensusEngine)->decide($this->withAbstentions(), $this->profile());

        $this->assertEqualsWithDelta(0.7, $result->score, 0.0001);
        $this->assertTrue($result->approved);
    }

    public function test_real_hold_still_weighs_in_the_average()
    {
        $votes = $this->withAbstentions();
        $votes[3] = new Vote(AgentRole::Quant, VoteAction::Hold, 0.6, reasons: ['sem tendência']);

        $result = (new ConsensusEngine)->decide($votes, $this->profile());

        $this->assertEqualsWithDelta(2.1 / 4, $result->score, 0.0001);
    }

    public function test_rejects_when_too_few_agents_voted()
    {
        $votes = [
            new Vote(AgentRole::Technical, VoteAction::Buy, 0.9, reasons: ['a']),
            new Vote(AgentRole::Risk, VoteAction::Buy, 0.9, reasons: ['b']),
            Vote::abstain(AgentRole::Quant, 'x'),
            Vote::abstain(AgentRole::News, 'y'),
        ];

        $result = (new ConsensusEngine)->decide($votes, $this->profile()->forceFill(['min_agreeing_voters' => 2]));

        $this->assertFalse($result->approved);
        $this->assertSame('Só 2 agentes votaram: mínimo de 3.', $result->reason);
    }

    public function test_all_abstentions_are_rejected_without_dividing_by_zero()
    {
        $result = (new ConsensusEngine)->decide([Vote::abstain(AgentRole::Quant, 'x')], $this->profile());

        $this->assertFalse($result->approved);
        $this->assertSame(0.0, $result->score);
        $this->assertSame('Só 0 agentes votaram: mínimo de 3.', $result->reason);
    }

    public function test_agreement_quorum_follows_the_valid_votes_not_the_whole_panel()
    {
        // Profile asks for 4 agreeing voters, but only 3 agents have data: all 3 agreeing is enough.
        $result = (new ConsensusEngine)->decide($this->withAbstentions(), $this->profile());

        $this->assertTrue($result->approved);
        $this->assertSame(3, $result->agreeing);

        // With one of the 3 disagreeing the quorum of 3 is not met.
        $votes = $this->withAbstentions();
        $votes[1] = new Vote(AgentRole::Skeptic, VoteAction::Sell, 0.1, reasons: ['b']);
        $result = (new ConsensusEngine)->decide($votes, $this->profile());

        $this->assertFalse($result->approved);
    }
}
