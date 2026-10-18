<?php

namespace Tests\Feature\Trading;

use App\Enums\MicroRule;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\MicroBacktest;
use App\Models\MicroCycle;
use App\Models\Portfolio;
use App\Trading\Micro\MicroBacktester;
use App\Trading\Micro\MicroTrader;
use App\Trading\Micro\MicroTrendAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MicroBacktestTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONS = ['net_margin_pct' => 0.2, 'fee_rate' => 0.001, 'slippage_bps' => 5, 'cooldown_minutes' => 15];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * One-minute candles from a price function, each opening at the previous close.
     *
     * @param  callable(int): float  $price
     * @return list<array{t: int, open: float, high: float, low: float, close: float}>
     */
    private function series(int $minutes, callable $price, float $wick = 0.002): array
    {
        $candles = [];
        $previous = $price(0);

        for ($i = 0; $i < $minutes; $i++) {
            $close = $price($i);
            $candles[] = ['t' => 1_700_000_000 + $i * 60, 'open' => $previous, 'high' => max($previous, $close) * (1 + $wick), 'low' => min($previous, $close) * (1 - $wick), 'close' => $close];
            $previous = $close;
        }

        return $candles;
    }

    /**
     * A steady rise with a 0.4% dip every 4 hours: the pullbacks the trend rule buys.
     *
     * @return list<array{t: int, open: float, high: float, low: float, close: float}>
     */
    private function uptrend(int $minutes = 3000): array
    {
        return $this->series($minutes, fn (int $i) => 100 * (1 + 0.00004 * $i) * (1 + 0.004 * sin($i * 2 * M_PI / 240)));
    }

    /**
     * @return list<array{t: int, open: float, high: float, low: float, close: float}>
     */
    private function noise(int $minutes = 4000): array
    {
        mt_srand(7);
        $price = 100.0;
        $path = [];

        for ($i = 0; $i < $minutes; $i++) {
            $price *= 1 + (mt_rand(-1000, 1000) / 1000) * 0.0015;
            $path[] = $price;
        }

        return $this->series($minutes, fn (int $i) => $path[$i], 0.0005);
    }

    public function test_the_trend_rule_buys_pullbacks_in_an_uptrend_and_never_a_flat_market()
    {
        $analysis = app(MicroTrendAnalysis::class);
        $candles = $this->uptrend();
        $entries = 0;

        for ($t = 1500; $t < count($candles); $t += 5) {
            $setup = $analysis->read(array_slice($candles, $t - 1499, 1500), 0.3);
            $entries += $setup?->entry ? 1 : 0;
        }

        $this->assertGreaterThan(0, $entries);

        $flat = $analysis->read($this->series(1500, fn () => 100.0), 0.3);
        $this->assertNotNull($flat);
        $this->assertFalse($flat->entry);
        $this->assertSame(['trend', 'support', 'reach', 'room'], array_column($flat->checks, 'key'));

        // Under 22 hours of candles there is no reading at all.
        $this->assertNull($analysis->read(array_slice($candles, 0, 1000), 0.3));
    }

    public function test_the_backtest_gains_on_a_clean_uptrend_and_loses_about_the_cost_on_noise()
    {
        $backtester = app(MicroBacktester::class);

        $trend = $backtester->summarize($backtester->simulate(MicroRule::TrendPullback, $this->uptrend(), self::OPTIONS));
        $this->assertGreaterThan(0, $trend['trades']);
        $this->assertGreaterThan(0, $trend['mean_pct']);

        $noise = $backtester->summarize($backtester->simulate(MicroRule::Scalp, $this->noise(), self::OPTIONS));
        $this->assertGreaterThan(5, $noise['trades']);
        $this->assertLessThan(0, $noise['mean_pct']);
    }

    public function test_a_decision_never_sees_candles_after_it()
    {
        $backtester = app(MicroBacktester::class);
        $candles = $this->uptrend();
        $cut = 2400;
        $changed = $candles;

        for ($i = $cut; $i < count($changed); $i++) {
            $changed[$i] = ['t' => $candles[$i]['t'], 'open' => 50.0, 'high' => 50.0, 'low' => 50.0, 'close' => 50.0];
        }

        $before = fn (array $trades) => array_values(array_filter($trades, fn (array $trade) => $candles[$cut]['t'] > $trade['opened_at'] + ($trade['minutes'] + 1) * 60));

        $original = $before($backtester->simulate(MicroRule::TrendPullback, $candles, self::OPTIONS));
        $this->assertNotEmpty($original);
        $this->assertSame($original, $before($backtester->simulate(MicroRule::TrendPullback, $changed, self::OPTIONS)));
    }

    public function test_the_command_reports_each_rule_and_saves_the_result()
    {
        $asset = Asset::factory()->create(['symbol' => 'DOGEUSDT']);
        $rows = [];

        foreach ($this->noise(1500) as $i => $candle) {
            $rows[] = ['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute()->subMinutes(1499 - $i), 'open' => $candle['open'], 'high' => $candle['high'], 'low' => $candle['low'], 'close' => $candle['close'], 'volume' => 1];
        }

        Candle::insert($rows);

        $this->artisan('micro:backtest', ['--days' => 2, '--save' => true])
            ->expectsOutputToContain('Atual (1 minuto)')
            ->expectsOutputToContain('Tendência com recuo')
            ->expectsOutputToContain('Atual + alta em 24 h e 4 h')
            ->expectsOutputToContain('NÃO PASSOU')
            ->assertSuccessful();

        $this->assertSame(3, MicroBacktest::count());
        $this->assertFalse(MicroBacktest::latestFor(MicroRule::TrendPullback)->passed);
        $this->assertSame(['DOGEUSDT'], MicroBacktest::latestFor(MicroRule::Scalp)->params['symbols']);

        $this->artisan('micro:backtest', ['--rule' => 'outra'])->assertExitCode(2);
    }

    public function test_a_real_wallet_takes_a_new_rule_only_after_it_passes_and_a_simulated_one_any_time()
    {
        $payload = ['micro_enabled' => '0', 'micro_order_value' => '5', 'micro_net_margin_pct' => '0.2', 'micro_rule' => 'trend_pullback'];

        $paper = Portfolio::factory()->create();
        $this->actingAs($paper->user)->put(route('portfolios.micro', $paper), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::TrendPullback, $paper->fresh()->micro_rule);

        $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasErrors('micro_rule');

        MicroBacktest::create(['rule' => MicroRule::TrendPullback, 'params' => ['days' => 30], 'summary' => ['validation' => ['trades' => 40, 'mean_pct' => -0.1], 'failures' => ['poucas operações']], 'passed' => false]);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasErrors('micro_rule');

        MicroBacktest::create(['rule' => MicroRule::TrendPullback, 'params' => ['days' => 30], 'summary' => ['validation' => ['trades' => 140, 'mean_pct' => 0.12, 'win_rate' => 48.0], 'failures' => []], 'passed' => true]);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::TrendPullback, $live->fresh()->micro_rule);

        // The current rule is always allowed, and the card shows each rule with its latest test.
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), ['micro_rule' => 'scalp'] + $payload)->assertSessionHasNoErrors();
        $this->actingAs($live->user)->get(route('portfolios.show', $live))->assertInertia(fn (Assert $page) => $page
            ->where('micro.rule', 'scalp')
            ->where('micro.rules.0.backtest', null)
            ->where('micro.rules.1.value', 'trend_pullback')
            ->where('micro.rules.1.backtest.passed', true)
            ->where('micro.rules.1.backtest.trades', 140));

        $this->actingAs(Portfolio::factory()->create()->user)->put(route('portfolios.micro', $live), $payload)->assertForbidden();
    }

    public function test_a_trend_cycle_keeps_its_own_target_stop_and_time_limit()
    {
        $asset = Asset::factory()->create(['symbol' => 'DOGEUSDT']);
        $candles = $this->uptrend();
        $analysis = app(MicroTrendAnalysis::class);
        $end = null;

        for ($t = 1500; $t < count($candles) && $end === null; $t++) {
            $end = $analysis->read(array_slice($candles, $t - 1499, 1500), 0.3)?->entry ? $t : null;
        }

        $this->assertNotNull($end);
        $rows = [];

        foreach (array_slice($candles, $end - 1499, 1500) as $i => $candle) {
            $rows[] = ['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute()->subMinutes(1499 - $i), 'open' => $candle['open'], 'high' => $candle['high'], 'low' => $candle['low'], 'close' => $candle['close'], 'volume' => 1];
        }

        Candle::insert($rows);

        $portfolio = Portfolio::factory()->create(['micro_enabled' => true, 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2, 'micro_rule' => MicroRule::TrendPullback]);
        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $cycle = MicroCycle::sole();
        $this->assertSame(MicroRule::TrendPullback, $cycle->rule);
        $this->assertSame(240, $cycle->max_hold_minutes);
        $target = ($cycle->target_price / $cycle->entry_price - 1) * 100;
        $stop = (1 - $cycle->stop_price / $cycle->entry_price) * 100;
        // The target pays 1.5 times the risk and never less than 4 round trips (4 x 0.30%).
        $this->assertGreaterThanOrEqual(1.2 - 0.01, $target);
        $this->assertGreaterThanOrEqual(1.5 - 0.05, $target / $stop);

        // Two hours in, a 60-minute cycle would be sold; this one keeps going until 4 hours.
        $cycle->update(['opened_at' => now()->subHours(2)]);
        app(MicroTrader::class)->run($portfolio);
        $this->assertSame('open', $cycle->fresh()->status);

        $cycle->update(['opened_at' => now()->subHours(5)]);
        app(MicroTrader::class)->run($portfolio);
        $this->assertSame('micro_time', $cycle->fresh()->exit_reason);
    }
}
