<?php

namespace Tests\Feature\Trading;

use App\Enums\MicroRule;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\MicroBacktest;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\BreakoutSignals;
use App\Trading\Micro\MicroOverview;
use App\Trading\Micro\MicroRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MicroBreakoutRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * 120 wide candles around 100, a 30-minute box of small candles, then `$last`.
     *
     * @param  list<array{high: float, low: float, close: float, volume: float}>|null  $box
     * @param  array{high: float, low: float, close: float, volume: float}  $last
     * @return list<array{high: float, low: float, close: float, volume: float}>
     */
    private function chart(array $last, ?array $box = null): array
    {
        $candles = [];

        for ($i = 0; $i < 120; $i++) {
            $candles[] = ['high' => 100.2, 'low' => 99.8, 'close' => 100.0, 'volume' => 1.0];
        }

        $box ??= array_fill(0, 30, ['high' => 100.05, 'low' => 99.95, 'close' => 100.0, 'volume' => 1.0]);

        return [...$candles, ...$box, $last];
    }

    /**
     * A box whose lows rise (99.90 in the first half, 99.97 in the second), its high
     * touched at 100.05 every few minutes and the volume picking up at the end.
     *
     * @return list<array{high: float, low: float, close: float, volume: float}>
     */
    private function pressingBox(): array
    {
        $box = [];

        for ($i = 0; $i < 30; $i++) {
            $box[] = ['high' => $i % 5 === 0 ? 100.05 : 100.0, 'low' => $i < 15 ? 99.9 : 99.97, 'close' => 100.0, 'volume' => $i >= 26 ? 2.0 : 1.0];
        }

        return $box;
    }

    public function test_confirmed_breakout_enters_on_a_loud_close_above_a_compressed_box()
    {
        $rules = app(MicroRules::class);

        $setup = $rules->read(MicroRule::Breakout, $this->chart(['high' => 100.25, 'low' => 100.0, 'close' => 100.2, 'volume' => 5.0]), 0.5, 0.3);
        $this->assertTrue($setup->entry);
        $this->assertSame(['squeeze', 'break', 'volume', 'reach'], array_column($setup->checks, 'key'));
        // The box is 0.10% tall, so the target is the floor of twice the round trip.
        $this->assertEqualsWithDelta(0.6, $setup->targetPct, 0.0001);
        // Half the box plus the break is 0.20%, under the round trip: the stop is the round trip.
        $this->assertEqualsWithDelta(0.3, $setup->stopPct, 0.0001);
        $this->assertSame(60, $setup->maxHoldMinutes);
        $this->assertStringStartsWith('Rompeu a caixa de 30 minutos com volume 5.0 vezes a média.', $setup->reasons[0]);

        // The same close with ordinary volume does not enter.
        $quiet = $rules->read(MicroRule::Breakout, $this->chart(['high' => 100.25, 'low' => 100.0, 'close' => 100.2, 'volume' => 1.0]), 0.5, 0.3);
        $this->assertFalse($quiet->entry);
        $this->assertSame([true, true, false, true], array_column($quiet->checks, 'ok'));
        $this->assertStringStartsWith('Volume alto: não.', $quiet->reasons[0]);

        // Inside the box, no break.
        $this->assertFalse($rules->read(MicroRule::Breakout, $this->chart(['high' => 100.05, 'low' => 99.95, 'close' => 100.0, 'volume' => 5.0]), 0.5, 0.3)->checks[1]['ok']);

        // A box as wide as the hours before it is not compressed.
        $wide = array_fill(0, 30, ['high' => 100.2, 'low' => 99.8, 'close' => 100.0, 'volume' => 1.0]);
        $this->assertFalse($rules->read(MicroRule::Breakout, $this->chart(['high' => 100.3, 'low' => 100.0, 'close' => 100.25, 'volume' => 5.0], $wide), 0.5, 0.3)->checks[0]['ok']);

        // Without volume in the candles the rule never enters.
        $noVolume = array_map(fn (array $c) => ['high' => $c['high'], 'low' => $c['low'], 'close' => $c['close']], $this->chart(['high' => 100.25, 'low' => 100.0, 'close' => 100.2, 'volume' => 5.0]));
        $this->assertFalse($rules->read(MicroRule::Breakout, $noVolume, 0.5, 0.3)->entry);

        // Under 2 h and a half of candles there is no reading.
        $this->assertNull($rules->read(MicroRule::Breakout, array_slice($this->chart(['high' => 100.25, 'low' => 100.0, 'close' => 100.2, 'volume' => 5.0]), -100), 0.5, 0.3));
    }

    public function test_pressure_enters_under_a_tested_high_with_rising_lows_and_volume()
    {
        $rules = app(MicroRules::class);
        $last = ['high' => 100.045, 'low' => 100.0, 'close' => 100.04, 'volume' => 2.0];

        $setup = $rules->read(MicroRule::BreakoutPressure, $this->chart($last, $this->pressingBox()), 0.5, 0.3);
        $this->assertTrue($setup->entry);
        $this->assertSame(['squeeze', 'lows', 'resistance', 'volume', 'reach'], array_column($setup->checks, 'key'));
        // Stop below the low of the second half of the box (99.97).
        $this->assertEqualsWithDelta(0.3, $setup->stopPct, 0.0001);
        $this->assertGreaterThanOrEqual(0.6, $setup->targetPct);
        $this->assertStringContainsString('6 toques na resistência', $setup->reasons[0]);

        // Lows falling instead of rising.
        $falling = array_map(fn (array $c, int $i) => ['low' => $i < 15 ? 99.97 : 99.9] + $c, $this->pressingBox(), array_keys($this->pressingBox()));
        $this->assertFalse($rules->read(MicroRule::BreakoutPressure, $this->chart($last, $falling), 0.5, 0.3)->checks[1]['ok']);

        // Price far from the high.
        $far = $rules->read(MicroRule::BreakoutPressure, $this->chart(['high' => 100.0, 'low' => 99.97, 'close' => 99.98, 'volume' => 2.0], $this->pressingBox()), 0.5, 0.3);
        $this->assertFalse($far->checks[2]['ok']);

        // Already above the high: that is the other rule.
        $this->assertFalse($rules->read(MicroRule::BreakoutPressure, $this->chart(['high' => 100.2, 'low' => 100.0, 'close' => 100.1, 'volume' => 2.0], $this->pressingBox()), 0.5, 0.3)->checks[2]['ok']);

        // Volume flat.
        $flat = array_map(fn (array $c) => ['volume' => 1.0] + $c, $this->pressingBox());
        $this->assertFalse($rules->read(MicroRule::BreakoutPressure, $this->chart(['volume' => 1.0] + $last, $flat), 0.5, 0.3)->checks[3]['ok']);
    }

    public function test_the_shared_signals_work_on_bars_of_any_size()
    {
        $signals = app(BreakoutSignals::class);

        $box = $signals->measure($this->chart(['high' => 100.045, 'low' => 100.0, 'close' => 100.04, 'volume' => 2.0], $this->pressingBox()));
        $this->assertSame(30, $box['bars']);
        $this->assertEqualsWithDelta(100.05, $box['high'], 0.0001);
        $this->assertTrue($box['rising_lows']);
        $this->assertSame(6, $box['touches']);
        $this->assertFalse($box['broke']);
        $this->assertEqualsWithDelta(0.01, $box['gap_pct'], 0.0001);

        // A 6-bar box over 12 bars before it, as the explorer would use on hourly candles.
        $hourly = $signals->measure(array_slice($this->chart(['high' => 100.25, 'low' => 100.0, 'close' => 100.2, 'volume' => 5.0]), -19), ['box' => 6, 'context' => 12]);
        $this->assertSame(6, $hourly['bars']);
        $this->assertTrue($hourly['broke']);
        $this->assertEqualsWithDelta(5.0, $hourly['volume_ratio'], 0.0001);
        $this->assertNull($signals->measure(array_fill(0, 10, ['high' => 1.0, 'low' => 1.0, 'close' => 1.0]), ['box' => 6, 'context' => 12]));
    }

    private function store(Asset $asset, array $candles): void
    {
        $rows = [];
        $last = count($candles) - 1;

        foreach ($candles as $i => $candle) {
            $rows[] = ['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute()->subMinutes($last - $i), 'open' => $candle['close'], 'high' => $candle['high'], 'low' => $candle['low'], 'close' => $candle['close'], 'volume' => $candle['volume']];
        }

        Candle::insert($rows);
    }

    public function test_the_focus_cards_read_the_volume_from_the_stored_candles_and_other_users_get_403()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->chart(['high' => 100.25, 'low' => 100.0, 'close' => 100.2, 'volume' => 5.0]));
        $portfolio = Portfolio::factory()->create(['micro_enabled' => true, 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2, 'micro_rule' => MicroRule::Breakout]);

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();

        $focus = collect(app(MicroOverview::class)->for($portfolio)['focus'])->keyBy('symbol');
        $this->assertSame([true, true, true, true], array_column($focus['DOGEUSDT']['checks'], 'ok'));

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.rule', 'breakout')
            ->where('micro.rules.3.value', 'breakout')
            ->where('micro.rules.4.value', 'breakout_pressure'));

        $this->assertSame(0, Order::count());
    }

    public function test_existing_wallets_keep_the_current_rule_and_a_real_one_takes_a_breakout_rule_only_after_it_passes()
    {
        $this->assertSame(MicroRule::Scalp, Portfolio::factory()->create()->fresh()->micro_rule);

        foreach (['breakout', 'breakout_pressure'] as $rule) {
            $payload = ['micro_enabled' => '0', 'micro_order_value' => '5', 'micro_net_margin_pct' => '0.2', 'micro_rule' => $rule];

            $paper = Portfolio::factory()->create();
            $this->actingAs($paper->user)->put(route('portfolios.micro', $paper), $payload)->assertSessionHasNoErrors();
            $this->assertSame($rule, $paper->fresh()->micro_rule->value);

            $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
            $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasErrors('micro_rule');

            MicroBacktest::create(['rule' => MicroRule::from($rule), 'params' => ['days' => 30], 'summary' => ['validation' => ['trades' => 140, 'mean_pct' => 0.05, 'win_rate' => 51.0], 'failures' => []], 'passed' => true]);
            $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasNoErrors();

            $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $live), $payload)->assertForbidden();
        }
    }

    public function test_the_backtest_runs_both_rules_by_name()
    {
        $candles = [];

        // Five boxes and breakouts in a row, so the replay has trades to count.
        for ($k = 0; $k < 5; $k++) {
            $candles = [...$candles, ...$this->chart(['high' => 100.25, 'low' => 100.0, 'close' => 100.2, 'volume' => 5.0])];
        }

        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $candles);

        $this->artisan('micro:backtest', ['--rule' => 'breakout', '--days' => 2, '--save' => true])
            ->expectsOutputToContain('Rompimento confirmado')
            ->assertSuccessful();
        $this->artisan('micro:backtest', ['--rule' => 'breakout_pressure', '--days' => 2])
            ->expectsOutputToContain('Pressão antes do rompimento')
            ->assertSuccessful();

        $backtest = MicroBacktest::latestFor(MicroRule::Breakout);
        $this->assertNotNull($backtest);
        $this->assertGreaterThan(0, $backtest->summary['fit']['trades'] + $backtest->summary['validation']['trades']);
    }
}
