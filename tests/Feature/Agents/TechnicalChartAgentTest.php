<?php

namespace Tests\Feature\Agents;

use App\Agents\Chart\ChartAnalyzer;
use App\Agents\Data\Scenario;
use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\LlmAgent;
use App\Agents\SignalScanner;
use App\Enums\AgentRole;
use App\Enums\VoteAction;
use App\Models\AgentVote;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarket;
use Tests\Concerns\ProvidesAgentInputs;
use Tests\TestCase;

class TechnicalChartAgentTest extends TestCase
{
    use BuildsMarket, ProvidesAgentInputs, RefreshDatabase;

    private function scenario(?array $chart): Scenario
    {
        return new Scenario(
            symbol: 'BTCUSDT',
            market: 'crypto',
            price: 100,
            asOf: now()->toIso8601String(),
            candles: array_fill(0, 30, ['id' => 'candle:x', 'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1]),
            news: [['id' => 'news:1', 'title' => 'Notícia']],
            position: null,
            cash: 1000,
            equity: 1000,
            fundamentals: ['pe' => 10],
            chart: $chart,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(): array
    {
        $bars = [];

        for ($i = 0; $i < 400; $i++) {
            $bars[] = ['t' => 1_700_006_400 + $i * 60, 'o' => 100.0, 'h' => 101.0, 'l' => 99.0, 'c' => 100.0 + ($i % 7) * 0.1, 'v' => 1.0];
        }

        return (new ChartAnalyzer)->analyze($bars, 1_700_006_400 + 86400);
    }

    private function answer(array $override = []): array
    {
        return $override + [
            'action' => 'buy', 'confidence' => 0.7, 'veto' => false, 'reasons' => ['Suporte segurou.'], 'evidence_ids' => [],
            'stop' => 98.0, 'target' => 106.0,
            'chart_scenario' => 'rompimento_alta', 'supports' => [99.0, 'x'], 'resistances' => [105.5], 'timeframes' => ['5m', '15m'],
        ];
    }

    public function test_technical_abstains_without_calling_the_model_when_no_timeframe_has_enough_bars()
    {
        $fake = new FakeLlmClient;
        $chart = (new ChartAnalyzer)->analyze([], now()->getTimestamp());

        $vote = (new LlmAgent(AgentRole::Technical, $fake))->vote($this->scenario($chart));

        $this->assertTrue($vote->abstained);
        $this->assertStringContainsString('Sem candles suficientes', $vote->reasons[0]);
        $this->assertSame([], $fake->calls);
    }

    public function test_technical_reads_the_chart_and_reports_scenario_and_levels()
    {
        $fake = new FakeLlmClient(fn () => $this->answer());

        $vote = (new LlmAgent(AgentRole::Technical, $fake))->vote($this->scenario($this->chart()));

        $prompt = $fake->calls[0]['prompt'];
        $this->assertStringContainsString('"chart"', $prompt);
        $this->assertStringContainsString('"5m"', $prompt);
        $this->assertStringContainsString('histórico insuficiente', $prompt);
        $this->assertStringNotContainsString('Notícia', $prompt);
        $this->assertStringContainsString('investidor', $fake->calls[0]['system']);

        $this->assertFalse($vote->abstained);
        $this->assertSame(VoteAction::Buy, $vote->action);
        $this->assertSame('rompimento_alta', $vote->analysis['scenario']);
        $this->assertSame([99.0], $vote->analysis['supports']);
        $this->assertSame([105.5], $vote->analysis['resistances']);
        $this->assertSame(['5m', '15m'], $vote->analysis['timeframes']);
        $this->assertContains('4h', $vote->analysis['omitted_timeframes']);
        $this->assertSame(98.0, $vote->stop);
        $this->assertSame(106.0, $vote->target);
    }

    public function test_stop_and_target_on_the_wrong_side_of_the_price_are_dropped()
    {
        $fake = new FakeLlmClient(fn () => $this->answer(['stop' => 103.0, 'target' => 95.0]));

        $vote = (new LlmAgent(AgentRole::Technical, $fake))->vote($this->scenario($this->chart()));

        $this->assertNull($vote->stop);
        $this->assertNull($vote->target);
    }

    public function test_an_unknown_chart_scenario_is_not_stored()
    {
        $fake = new FakeLlmClient(fn () => $this->answer(['chart_scenario' => 'foguete']));

        $vote = (new LlmAgent(AgentRole::Technical, $fake))->vote($this->scenario($this->chart()));

        $this->assertNull($vote->analysis['scenario']);
    }

    public function test_other_agents_never_see_the_chart_and_the_technical_never_sees_the_news()
    {
        $fake = new FakeLlmClient(fn () => $this->answer());

        (new LlmAgent(AgentRole::Risk, $fake))->vote($this->scenario($this->chart()));
        (new LlmAgent(AgentRole::News, $fake))->vote($this->scenario($this->chart()));

        $this->assertStringNotContainsString('"chart"', $fake->calls[0]['prompt']);
        $this->assertStringNotContainsString('"chart"', $fake->calls[1]['prompt']);
        $this->assertStringContainsString('Notícia', $fake->calls[1]['prompt']);
    }

    public function test_the_signal_keeps_the_chart_and_the_technical_vote_keeps_its_analysis()
    {
        $this->app->instance(LlmClient::class, new FakeLlmClient(fn () => $this->answer()));

        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->history($asset, 100);
        $this->quote($asset, 100);
        $this->provideAgentInputs($asset);
        Portfolio::factory()->create(['market' => $asset->market]);

        app(SignalScanner::class)->scan($asset);
        $signal = Signal::query()->firstOrFail();

        $this->assertTrue($signal->scenario['chart']['usable']);
        $this->assertArrayHasKey('5m', $signal->scenario['chart']['timeframes']);

        $vote = AgentVote::query()->where('agent', AgentRole::Technical)->latest('round')->firstOrFail();
        $this->assertSame('rompimento_alta', $vote->analysis['scenario']);
        $this->assertNull(AgentVote::query()->where('agent', AgentRole::Risk)->firstOrFail()->analysis);
    }
}
