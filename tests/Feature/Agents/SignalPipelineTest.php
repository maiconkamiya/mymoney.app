<?php

namespace Tests\Feature\Agents;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\SignalScanner;
use App\Enums\Market;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarket;
use Tests\Concerns\ProvidesAgentInputs;
use Tests\TestCase;

class SignalPipelineTest extends TestCase
{
    use BuildsMarket, ProvidesAgentInputs, RefreshDatabase;

    private function agentsVote(string $action, float $confidence, bool $riskVeto = false): FakeLlmClient
    {
        $fake = new FakeLlmClient(fn (string $system) => [
            'action' => $action,
            'confidence' => $confidence,
            'veto' => $riskVeto && str_contains($system, 'agente de risco'),
            'reasons' => ['teste'],
            'evidence_ids' => [],
            'stop' => 95.0,
            'target' => 110.0,
        ]);

        $this->app->instance(LlmClient::class, $fake);

        return $fake;
    }

    private function scan(bool $autoExecute = false): Signal
    {
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        $this->provideAgentInputs($asset);
        Portfolio::factory()->create(['market' => $asset->market, 'auto_execute' => $autoExecute]);

        $signals = app(SignalScanner::class)->scan($asset);
        $this->assertCount(1, $signals);

        return $signals[0]->fresh();
    }

    public function test_agreeing_agents_approve_and_wait_for_manual_confirmation()
    {
        $fake = $this->agentsVote('buy', 0.8);

        $signal = $this->scan();

        $this->assertSame(SignalStage::Approved, $signal->stage);
        $this->assertSame(95.0, $signal->stop_price);
        $this->assertSame(0, $signal->orders()->count());
        // Fundamentals abstains on crypto without calling the model: 4 first-round votes, the skeptic, then 4 revisions.
        $this->assertCount(9, $fake->calls);
        $this->assertSame(11, $signal->votes()->count());
    }

    public function test_auto_execute_portfolio_books_the_order()
    {
        $this->agentsVote('buy', 0.8);

        $signal = $this->scan(autoExecute: true);

        $this->assertSame(SignalStage::Executed, $signal->stage, (string) $signal->decision_reason);
        $portfolio = $signal->portfolio->fresh();
        $this->assertCount(1, $portfolio->openPositions);
        $this->assertLessThan(10_000, $portfolio->cash);
        $this->assertEqualsWithDelta(5, $portfolio->openPositions->first()->qty, 0.01);
    }

    public function test_toggling_the_switch_changes_what_happens_to_the_next_approved_signal()
    {
        $this->agentsVote('buy', 0.8);
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        $this->provideAgentInputs($asset);
        $portfolio = Portfolio::factory()->create(['market' => $asset->market, 'auto_execute' => true]);

        $portfolio->update(['auto_execute' => false]);
        $pending = app(SignalScanner::class)->scan($asset)[0]->fresh();
        $this->assertSame(SignalStage::Approved, $pending->stage);
        $this->assertSame(0, $pending->orders()->count());
        $pending->moveTo(SignalStage::Rejected, 'descartado no teste');

        $portfolio->update(['auto_execute' => true]);
        $executed = app(SignalScanner::class)->scan($asset)[0]->fresh();
        $this->assertSame(SignalStage::Executed, $executed->stage, (string) $executed->decision_reason);
    }

    public function test_all_hold_skips_the_challenge_round_and_rejects()
    {
        $fake = $this->agentsVote('hold', 0);

        $signal = $this->scan();

        $this->assertSame(SignalStage::Rejected, $signal->stage);
        $this->assertCount(4, $fake->calls);
    }

    public function test_risk_veto_rejects_the_signal()
    {
        $this->agentsVote('buy', 1, riskVeto: true);

        $signal = $this->scan(autoExecute: true);

        $this->assertSame(SignalStage::Rejected, $signal->stage);
        $this->assertStringContainsString('Veto', $signal->decision_reason);
        $this->assertSame(0, $signal->orders()->count());
    }

    public function test_scanner_does_not_open_a_second_signal_while_one_is_in_flight()
    {
        $this->agentsVote('buy', 0.8);
        $signal = $this->scan();

        $this->assertSame([], app(SignalScanner::class)->scan($signal->asset));
    }

    public function test_scanner_skips_assets_without_a_fresh_quote()
    {
        $fake = $this->agentsVote('buy', 0.8);
        $asset = Asset::factory()->create();
        Portfolio::factory()->create(['market' => $asset->market]);

        $this->assertSame([], app(SignalScanner::class)->scan($asset));

        $this->quote($asset, 100, now()->subHours(3));
        $this->assertSame([], app(SignalScanner::class)->scan($asset));
        $this->assertSame([], $fake->calls);
        $this->assertSame(0, Signal::count());
    }

    public function test_scanner_skips_b3_assets_while_the_session_is_closed()
    {
        $fake = $this->agentsVote('buy', 0.8);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 15:00:00', 'UTC')); // sábado
        $asset = Asset::factory()->create(['market' => Market::B3, 'symbol' => 'PETR4', 'lot_size' => 100]);
        $this->quote($asset, 38);
        Portfolio::factory()->create(['market' => Market::B3]);

        $this->assertSame([], app(SignalScanner::class)->scan($asset));
        $this->assertSame([], $fake->calls);
    }

    public function test_scanner_expires_approved_signals_nobody_confirmed()
    {
        $this->agentsVote('buy', 0.8);
        $signal = $this->scan();
        $this->assertSame(SignalStage::Approved, $signal->stage);

        $this->travel(config('trading.signal_ttl_minutes') + 1)->minutes();
        $this->quote($signal->asset, 100);

        $this->assertCount(1, app(SignalScanner::class)->scan($signal->asset));
        $this->assertSame(SignalStage::Rejected, $signal->fresh()->stage);
        $this->assertStringContainsString('Expirado', $signal->fresh()->decision_reason);
    }

    public function test_approved_sell_without_an_open_position_is_rejected_at_analysis()
    {
        $this->agentsVote('sell', 0.8);
        $signal = $this->scan();

        $this->assertSame(SignalStage::Rejected, $signal->stage);
        $this->assertStringContainsString('não há posição aberta', $signal->decision_reason);
    }

    public function test_agents_without_inputs_abstain_without_calling_the_model_and_stay_out_of_the_score()
    {
        $fake = $this->agentsVote('buy', 0.8);
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        Portfolio::factory()->create(['market' => $asset->market]);

        $signal = app(SignalScanner::class)->scan($asset)[0]->fresh();

        // Only technical, skeptic and risk have inputs: the three answer, the rest abstain.
        $abstained = $signal->votes()->where('round', 1)->where('abstained', true)->pluck('agent')->map->value->sort()->values()->all();
        $this->assertSame(['fundamentals', 'news', 'quant'], $abstained);
        $this->assertEqualsWithDelta(0.8, $signal->score, 0.0001);
        $this->assertSame(SignalStage::Approved, $signal->stage);
        $this->assertCount(2 + 1 + 2, $fake->calls);
    }
}
