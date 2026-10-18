<?php

namespace Tests\Feature\Agents;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\SignalScanner;
use App\Enums\AgentRole;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarket;
use Tests\Concerns\ProvidesAgentInputs;
use Tests\TestCase;

/**
 * The switches in Configurações > Custos e serviços that cut model calls.
 */
class CostSwitchesTest extends TestCase
{
    use BuildsMarket, ProvidesAgentInputs, RefreshDatabase;

    private function agentsVote(string $action, float $confidence): FakeLlmClient
    {
        $fake = new FakeLlmClient(fn () => [
            'action' => $action,
            'confidence' => $confidence,
            'veto' => false,
            'reasons' => ['teste'],
            'evidence_ids' => [],
            'stop' => 95.0,
            'target' => 110.0,
        ]);

        $this->app->instance(LlmClient::class, $fake);

        return $fake;
    }

    private function scan(): Signal
    {
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->history($asset, 100);
        $this->quote($asset, 100);
        $this->provideAgentInputs($asset);
        Portfolio::factory()->create(['market' => $asset->market]);

        return app(SignalScanner::class)->scan($asset)[0]->fresh();
    }

    private function callsTo(FakeLlmClient $fake, string $role): int
    {
        return collect($fake->calls)->filter(fn (array $call) => str_contains($call['system'], $role))->count();
    }

    public function test_risk_is_not_asked_when_the_first_round_has_no_direction()
    {
        config(['agents.risk_needs_direction' => true]);
        $fake = $this->agentsVote('hold', 0);

        $signal = $this->scan();

        $this->assertSame(SignalStage::Rejected, $signal->stage);
        $this->assertSame(0, $this->callsTo($fake, 'agente de risco'));
        $this->assertCount(3, $fake->calls);
        $risk = $signal->votes()->where('agent', AgentRole::Risk->value)->sole();
        $this->assertTrue((bool) $risk->abstained);
    }

    public function test_risk_is_still_asked_when_someone_points_a_direction()
    {
        config(['agents.risk_needs_direction' => true]);
        $fake = $this->agentsVote('buy', 0.8);

        $signal = $this->scan();

        $this->assertSame(SignalStage::Approved, $signal->stage);
        $this->assertSame(2, $this->callsTo($fake, 'agente de risco'));
    }

    public function test_risk_is_asked_by_default()
    {
        $fake = $this->agentsVote('hold', 0);

        $this->scan();

        $this->assertSame(1, $this->callsTo($fake, 'agente de risco'));
        $this->assertStringContainsString("{\n", $fake->calls[0]['prompt']);
    }

    public function test_skeptic_is_skipped_when_the_late_entry_rule_would_block_the_buy()
    {
        config(['agents.skip_skeptic_on_late_entry' => true]);
        $fake = $this->agentsVote('buy', 0.8);
        $asset = Asset::factory()->create(['lot_size' => '0.001']);

        for ($i = 240; $i >= 1; $i--) {
            $this->quote($asset, $i > 60 ? 100 : 100 + (61 - $i) * 0.5, now()->subMinutes($i));
        }

        $this->quote($asset, 130.5);
        $this->provideAgentInputs($asset);
        Portfolio::factory()->create(['market' => $asset->market, 'auto_execute' => true]);

        $signal = app(SignalScanner::class)->scan($asset)[0]->fresh();

        $this->assertSame(SignalStage::Rejected, $signal->stage);
        $this->assertStringStartsWith('Entrada tardia:', $signal->decision_reason);
        $this->assertStringEndsWith('O Cético não foi consultado.', $signal->decision_reason);
        $this->assertSame(0, $this->callsTo($fake, 'Você é o cético'));
        $this->assertTrue($signal->calculation['skeptic_skipped']);
        $this->assertTrue($signal->calculation['late_entry']['blocked']);
        $this->assertSame(0, $signal->orders()->count());
    }

    public function test_skeptic_still_runs_on_a_buy_the_rule_lets_through()
    {
        config(['agents.skip_skeptic_on_late_entry' => true]);
        $fake = $this->agentsVote('buy', 0.8);

        $signal = $this->scan();

        $this->assertSame(SignalStage::Approved, $signal->stage);
        $this->assertSame(1, $this->callsTo($fake, 'Você é o cético'));
    }

    public function test_compact_json_sends_the_scenario_without_indentation()
    {
        config(['agents.compact_json' => true]);
        $fake = $this->agentsVote('hold', 0);

        $this->scan();

        $this->assertNotEmpty($fake->calls);
        $this->assertTrue(collect($fake->calls)->every(fn (array $call) => str_contains($call['prompt'], 'Cenário:') && ! str_contains($call['prompt'], "{\n")));
    }
}
