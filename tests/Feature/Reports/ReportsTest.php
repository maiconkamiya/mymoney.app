<?php

namespace Tests\Feature\Reports;

use App\Agents\Consensus\ConsensusEngine;
use App\Agents\Data\Vote;
use App\Enums\AgentRole;
use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SignalStage;
use App\Enums\VoteAction;
use App\Models\AgentScore;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\User;
use App\Reports\AgentScoreboard;
use App\Reports\PerformanceReport;
use App\Reports\TaxReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    public function test_snapshot_command_records_equity_and_drawdown()
    {
        $asset = Asset::factory()->create();
        $this->quote($asset, 100);
        $portfolio = Portfolio::factory()->create(['cash' => 9_000]);
        $portfolio->positions()->create(['asset_id' => $asset->id, 'qty' => 10, 'avg_price' => 100, 'opened_at' => now()]);
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDay(), 'equity' => 12_000, 'cash' => 12_000, 'exposure' => 0]);

        $this->artisan('portfolios:snapshot')->assertSuccessful();

        $snapshot = $portfolio->snapshots()->latest('taken_at')->first();
        $this->assertEquals(10_000, $snapshot->equity);
        $this->assertEquals(1_000, $snapshot->exposure);
        $this->assertEqualsWithDelta(0.16667, $snapshot->drawdown, 1e-5);
    }

    public function test_performance_report_compares_with_the_benchmark()
    {
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $this->quote($btc, 100, now()->subDays(2));
        $this->quote($btc, 110, now()->subHours(2));
        $portfolio = Portfolio::factory()->create(['created_at' => now()->subDays(2)]);
        foreach ([[1, 10_500], [0, 11_000]] as [$ago, $equity]) {
            PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDays($ago)->subHour(), 'equity' => $equity, 'cash' => $equity, 'exposure' => 0]);
        }

        $report = app(PerformanceReport::class)->build($portfolio);

        $this->assertSame('BTCUSDT', $report['benchmark']);
        $this->assertEqualsWithDelta(0.10, $report['return'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $report['benchmark_return'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $report['excess_return'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $report['max_drawdown'], 1e-9);
        $this->assertCount(3, $report['curve']);
    }

    public function test_scoreboard_scores_agents_and_weights_the_consensus()
    {
        $asset = Asset::factory()->create();
        $this->quote($asset, 110);
        $portfolio = Portfolio::factory()->create();

        for ($i = 0; $i < AgentScoreboard::MIN_SAMPLE; $i++) {
            $signal = Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Executed, 'side' => 'buy', 'entry_price' => 100]);
            $signal->votes()->create(['agent' => AgentRole::Technical, 'round' => 1, 'action' => VoteAction::Sell, 'confidence' => 0.8]);
            $signal->votes()->create(['agent' => AgentRole::Technical, 'round' => 2, 'action' => VoteAction::Buy, 'confidence' => 0.8]);
            $signal->votes()->create(['agent' => AgentRole::News, 'round' => 2, 'action' => VoteAction::Sell, 'confidence' => 0.8]);
            $signal->votes()->create(['agent' => AgentRole::Quant, 'round' => 2, 'action' => VoteAction::Hold, 'confidence' => 0.5]);
        }

        $this->artisan('agents:score')->assertSuccessful();

        $technical = AgentScore::where('agent', AgentRole::Technical)->where('market', Market::Crypto)->sole();
        $this->assertSame(10, $technical->signals);
        $this->assertEquals(1.0, $technical->hit_rate);
        $this->assertEquals(1.5, $technical->weight);
        $news = AgentScore::where('agent', AgentRole::News)->where('market', Market::Crypto)->sole();
        $this->assertEquals(0.0, $news->hit_rate);
        $this->assertEquals(0.5, $news->weight);
        $quant = AgentScore::where('agent', AgentRole::Quant)->where('market', Market::Crypto)->sole();
        $this->assertEquals(0, $quant->signals);
        $this->assertEquals(1.0, $quant->weight);

        $weights = AgentScore::query()->pluck('weight', 'agent')->map(fn ($w) => (float) $w)->all();
        $votes = [
            new Vote(AgentRole::Technical, VoteAction::Buy, 1.0),
            new Vote(AgentRole::News, VoteAction::Sell, 1.0),
        ];
        $result = app(ConsensusEngine::class)->decide($votes, RiskProfile::factory()->make(), $weights);
        $this->assertSame(OrderSide::Buy, $result->side);
        $this->assertEqualsWithDelta(0.5, $result->score, 1e-9);
    }

    public function test_few_signals_keep_the_neutral_weight()
    {
        $this->assertSame(1.0, AgentScoreboard::weight(AgentScoreboard::MIN_SAMPLE - 1, 1.0));
        $this->assertSame(1.2, AgentScoreboard::weight(20, 0.7));
    }

    public function test_tax_report_replays_fills_with_average_cost()
    {
        $asset = Asset::factory()->create(['symbol' => 'PETR4']);
        $portfolio = Portfolio::factory()->create();
        $fills = [
            [OrderSide::Buy, 10, 100, 1, '2026-01-10'],
            [OrderSide::Buy, 10, 120, 1, '2026-01-20'],
            [OrderSide::Sell, 10, 130, 2, '2026-02-05'],
        ];

        foreach ($fills as [$side, $qty, $price, $fee, $date]) {
            $order = $portfolio->orders()->create(['asset_id' => $asset->id, 'broker' => 'paper', 'side' => $side, 'type' => OrderType::Market, 'qty' => $qty, 'status' => OrderStatus::Filled]);
            $order->fills()->create(['qty' => $qty, 'price' => $price, 'fee' => $fee, 'executed_at' => $date]);
        }

        $months = app(TaxReport::class)->months($portfolio);

        // Average cost = (1000 + 1 + 1200 + 1) / 20 = 110.1; result = 1300 - 2 - 1101 = 197.
        $this->assertSame([
            ['month' => '2026-01', 'bought' => 2200.0, 'sold' => 0.0, 'fees' => 2.0, 'result' => 0.0],
            ['month' => '2026-02', 'bought' => 0.0, 'sold' => 1300.0, 'fees' => 2.0, 'result' => 197.0],
        ], $months);
    }

    public function test_tax_csv_export_and_portfolio_page_props()
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'ABCUSDT']);
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Cripto']);
        $order = $portfolio->orders()->create(['asset_id' => $asset->id, 'broker' => 'paper', 'side' => OrderSide::Buy, 'type' => OrderType::Market, 'qty' => 1, 'status' => OrderStatus::Filled]);
        $order->fills()->create(['qty' => 1, 'price' => 50, 'fee' => 0.5, 'executed_at' => '2026-03-01']);

        $response = $this->actingAs($user)->get(route('portfolios.reports.tax', $portfolio));

        $response->assertOk()->assertDownload('ir-cripto.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('data,mes,ativo,lado,quantidade,preco,valor,taxas,resultado', $csv);
        $this->assertStringContainsString('2026-03-01,2026-03,ABCUSDT,buy,1,50,50,0.5,0', $csv);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolios/Show')
                ->has('performance.curve')
                ->has('agent_scores')
                ->has('tax_months', 1));

        $this->actingAs(User::factory()->create())->get(route('portfolios.reports.tax', $portfolio))->assertForbidden();
    }
}
