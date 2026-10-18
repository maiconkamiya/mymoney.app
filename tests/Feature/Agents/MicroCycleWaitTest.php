<?php

namespace Tests\Feature\Agents;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\SignalPipeline;
use App\Agents\SignalScanner;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\MicroCycle;
use App\Models\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarket;
use Tests\Concerns\ProvidesAgentInputs;
use Tests\TestCase;

/**
 * A pair with an open micro cycle is left to the cycle: the swarm is not
 * called for it and a sell it approves meanwhile is not executed.
 */
class MicroCycleWaitTest extends TestCase
{
    use BuildsMarket, ProvidesAgentInputs, RefreshDatabase;

    private Asset $asset;

    private Portfolio $portfolio;

    private FakeLlmClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeLlmClient(fn () => [
            'action' => 'sell',
            'confidence' => 0.9,
            'veto' => false,
            'reasons' => ['teste'],
            'evidence_ids' => [],
            'stop' => 105.0,
            'target' => 90.0,
        ]);
        $this->app->instance(LlmClient::class, $this->fake);

        $this->asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->history($this->asset, 100);
        $this->quote($this->asset, 100);
        $this->provideAgentInputs($this->asset);
        $this->portfolio = Portfolio::factory()->create(['market' => $this->asset->market, 'auto_execute' => true]);
    }

    private function openCycle(): MicroCycle
    {
        $position = $this->portfolio->positions()->create(['asset_id' => $this->asset->id, 'qty' => 0.05, 'avg_price' => 100, 'opened_at' => now()]);

        return MicroCycle::create([
            'portfolio_id' => $this->portfolio->id,
            'asset_id' => $this->asset->id,
            'position_id' => $position->id,
            'status' => 'open',
            'qty' => 0.05,
            'entry_price' => 100,
            'target_price' => 101,
            'stop_price' => 99,
            'gross_margin_pct' => 1,
            'opened_at' => now(),
        ]);
    }

    public function test_the_swarm_is_not_called_for_a_pair_with_an_open_cycle()
    {
        $this->openCycle();

        $this->assertSame([], app(SignalScanner::class)->scan($this->asset));
        $this->assertSame(0, $this->portfolio->signals()->count());
        $this->assertSame([], $this->fake->calls);
    }

    public function test_a_closed_cycle_does_not_hold_the_swarm()
    {
        $this->openCycle()->update(['status' => 'closed', 'closed_at' => now()]);

        $this->assertCount(1, app(SignalScanner::class)->scan($this->asset));
    }

    public function test_a_sell_approved_while_the_cycle_opened_is_rejected_and_sells_nothing()
    {
        $signal = $this->portfolio->signals()->create(['asset_id' => $this->asset->id, 'stage' => SignalStage::Scanned]);
        $this->openCycle();

        app(SignalPipeline::class)->analyze($signal);
        $signal->refresh();

        $this->assertSame(SignalStage::Rejected, $signal->stage);
        $this->assertSame(SignalPipeline::MICRO_CYCLE_OPEN, $signal->decision_reason);
        $this->assertSame(0, $signal->orders()->count());
        $this->assertSame(1, $this->portfolio->openPositions()->count());
    }

    public function test_with_the_switch_off_the_swarm_sells_as_before()
    {
        config(['agents.wait_micro_cycle' => false]);
        $this->openCycle();

        $signal = app(SignalScanner::class)->scan($this->asset)[0]->fresh();

        $this->assertSame(SignalStage::Executed, $signal->stage);
        $this->assertSame(0, $this->portfolio->openPositions()->count());
    }
}
