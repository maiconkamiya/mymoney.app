<?php

namespace Tests\Feature\Trading;

use App\Enums\MicroRule;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\MicroBacktest;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Micro\MicroOverview;
use App\Trading\Micro\MicroRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MicroRegra30MinutosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * The current rule's entry picture in 120 bars (flat, one spike, a slow
     * climb), each bar as `$size` one-minute candles with its own range.
     *
     * @return list<array{high: float, low: float, close: float}>
     */
    private function picture(int $size = 30, float $range = 0.3): array
    {
        $candles = [];

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + 0.15 * ($i - 94) / 25;

            for ($m = 0; $m < $size; $m++) {
                $candles[] = ['high' => $close + $range + ($i === 80 && $m === 0 ? 0.9 : 0), 'low' => $close - $range, 'close' => $close];
            }
        }

        return $candles;
    }

    /**
     * A day falling from 102 to 100, then the entry picture in the last two hours of 1-minute candles.
     *
     * @return list<array{high: float, low: float, close: float}>
     */
    private function fallingDay(): array
    {
        $candles = [];

        for ($i = 0; $i < 3480; $i++) {
            $close = 102.0 - 2.0 * $i / 3480;
            $candles[] = ['high' => $close + 0.05, 'low' => $close - 0.05, 'close' => $close];
        }

        return [...$candles, ...$this->picture(1, 0.05)];
    }

    private function store(Asset $asset, array $candles): void
    {
        $rows = [];
        $last = count($candles) - 1;

        foreach ($candles as $i => $candle) {
            $rows[] = ['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute()->subMinutes($last - $i), 'open' => $candle['close'], 'high' => $candle['high'], 'low' => $candle['low'], 'close' => $candle['close'], 'volume' => 1];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Candle::insert($chunk);
        }
    }

    public function test_reads_the_current_rule_on_30_minute_bars()
    {
        $rules = app(MicroRules::class);
        $this->assertSame(3600, $rules->lookbackMinutes(MicroRule::Scalp30));
        $this->assertSame(120, $rules->lookbackMinutes(MicroRule::Scalp));

        $setup = $rules->read(MicroRule::Scalp30, $this->picture(), 0.5);
        $bars = $rules->read(MicroRule::Scalp, $this->picture(1), 0.5);

        // Same picture, same verdict: the 30 one-minute candles of a bar read as one candle.
        $this->assertTrue($setup->entry);
        $this->assertSame(array_column($bars->checks, 'ok'), array_column($setup->checks, 'ok'));
        $this->assertSame($bars->support, $setup->support);
        $this->assertSame($bars->resistance, $setup->resistance);
        $this->assertSame('Média de 9 velas de 30 minutos acima da de 21, e a de 21 subindo.', $setup->checks[0]['detail']);

        // The reach counts bars: the range of one 30-minute bar times the root of the 2 bars in 60 minutes.
        $this->assertEqualsWithDelta($bars->atrPct * sqrt(2), $setup->reachPct, 0.0001);

        // The setup keeps the 1-minute range, so the suggested price and the pairs in focus read as before.
        $this->assertLessThan($bars->atrPct, $setup->atrPct);

        // Under 40 bars (20 hours of 1-minute candles) there is no reading.
        $this->assertNull($rules->read(MicroRule::Scalp30, array_slice($this->picture(), -1199), 0.5));
    }

    public function test_the_1_minute_rule_enters_where_the_30_minute_one_sees_a_falling_chart()
    {
        $rules = app(MicroRules::class);
        $candles = $this->fallingDay();

        $this->assertTrue($rules->read(MicroRule::Scalp, array_slice($candles, -120), 0.5)->entry);

        $setup = $rules->read(MicroRule::Scalp30, $candles, 0.5);
        $this->assertFalse($setup->entry);
        $this->assertFalse($setup->checks[0]['ok']);
        $this->assertContains('Sem tendência de alta (médias de 9 e 21 velas de 30 minutos).', $setup->reasons);
    }

    public function test_the_card_lists_the_rule_explain_reads_it_and_other_users_get_403()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->picture());
        $portfolio = Portfolio::factory()->create(['micro_enabled' => true, 'micro_entry_mode' => 'market', 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2, 'micro_rule' => MicroRule::Scalp30]);

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();

        $focus = collect(app(MicroOverview::class)->for($portfolio)['focus'])->keyBy('symbol');
        $this->assertSame([true, true, true, true], array_column($focus['DOGEUSDT']['checks'], 'ok'));

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.rule', 'scalp_30m')
            ->where('micro.rules.6.value', 'scalp_30m')
            ->where('micro.rules.6.label', 'Atual (30 minutos)')
            ->where('micro.rule_hold_minutes', 60));

        Artisan::call('micro:run', ['--explain' => true]);
        $output = Artisan::output();

        foreach (['Atual (30 minutos)', 'DOGEUSDT', 'ENTRA'] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_wallets_keep_their_rule_and_a_real_one_takes_this_rule_only_after_it_passes()
    {
        $this->assertSame(MicroRule::Scalp, Portfolio::factory()->create()->fresh()->micro_rule);

        $payload = ['micro_enabled' => '0', 'micro_order_value' => '5', 'micro_net_margin_pct' => '0.2', 'micro_rule' => 'scalp_30m'];

        $paper = Portfolio::factory()->create();
        $this->actingAs($paper->user)->put(route('portfolios.micro', $paper), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::Scalp30, $paper->fresh()->micro_rule);

        $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasErrors('micro_rule');
        $this->assertSame(MicroRule::Scalp, $live->fresh()->micro_rule);

        $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $paper), ['micro_rule' => 'scalp'] + $payload)->assertForbidden();
        $this->assertSame(MicroRule::Scalp30, $paper->fresh()->micro_rule);
    }

    public function test_the_backtest_runs_the_rule_by_name()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->picture());

        $this->artisan('micro:backtest', ['--rule' => 'scalp_30m', '--days' => 3, '--save' => true])
            ->expectsOutputToContain('Atual (30 minutos)')
            ->assertSuccessful();

        $this->assertNotNull(MicroBacktest::latestFor(MicroRule::Scalp30));
    }
}
