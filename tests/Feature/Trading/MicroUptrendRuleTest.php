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

class MicroUptrendRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * 1450 one-minute candles: a slow line from `$dayStart` to 100, then the
     * current rule's entry picture (flat, one spike, a slow climb to `$end`).
     *
     * @return list<array{high: float, low: float, close: float}>
     */
    private function day(float $dayStart, float $end = 100.15): array
    {
        $candles = [];

        for ($i = 0; $i < 1330; $i++) {
            $close = $dayStart + (100.0 - $dayStart) * $i / 1330;
            $candles[] = ['high' => $close + 0.05, 'low' => $close - 0.05, 'close' => $close];
        }

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + ($end - 100.0) * ($i - 94) / 25;
            $candles[] = ['high' => $close + 0.05 + ($i === 80 ? 0.9 : 0), 'low' => $close - 0.05, 'close' => $close];
        }

        return $candles;
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

    private function micro(array $attributes = []): Portfolio
    {
        return Portfolio::factory()->create($attributes + ['micro_enabled' => true, 'micro_entry_mode' => 'market', 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2, 'micro_rule' => MicroRule::ScalpUptrend]);
    }

    public function test_enters_only_when_the_pair_is_up_in_24_and_4_hours()
    {
        $rules = app(MicroRules::class);

        $rising = $rules->read(MicroRule::ScalpUptrend, $this->day(98.0), 0.5);
        $this->assertTrue($rising->entry);
        $this->assertSame(['trend', 'support', 'reach', 'room', 'uptrend'], array_column($rising->checks, 'key'));
        $this->assertTrue($rising->checks[4]['ok']);
        $this->assertStringContainsString('Alta de +2.18% em 24 h', implode(' ', $rising->reasons));

        // The same last two hours after a falling day: the current rule enters, this one does not.
        $falling = $rules->read(MicroRule::ScalpUptrend, $this->day(102.0), 0.5);
        $this->assertTrue($rules->read(MicroRule::Scalp, array_slice($this->day(102.0), -120), 0.5)->entry);
        $this->assertFalse($falling->entry);
        $this->assertFalse($falling->checks[4]['ok']);
        $this->assertSame('Alta em 24 h e 4 h', $falling->checks[4]['label']);
        $this->assertStringStartsWith('Sem alta em 24 h e 4 h (-1.80% em 24 h', $falling->reasons[0]);

        // Under 24 hours of candles there is no reading.
        $this->assertNull($rules->read(MicroRule::ScalpUptrend, array_slice($this->day(98.0), -1000), 0.5));
    }

    public function test_up_in_24_hours_but_down_in_4_hours_does_not_enter()
    {
        $candles = $this->day(90.0);

        // Push the price of 4 h ago above the last close.
        for ($i = count($candles) - 300; $i < count($candles) - 200; $i++) {
            $candles[$i] = ['high' => 101.05, 'low' => 100.95, 'close' => 101.0];
        }

        $setup = app(MicroRules::class)->read(MicroRule::ScalpUptrend, $candles, 0.5);
        $this->assertFalse($setup->entry);
        $this->assertFalse($setup->checks[4]['ok']);
    }

    public function test_the_focus_cards_show_the_fifth_condition_explain_gives_the_reason_and_other_users_get_403()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->day(98.0));
        $this->store(Asset::factory()->create(['symbol' => 'XRPUSDT']), $this->day(102.0));
        $portfolio = $this->micro();

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();

        $focus = collect(app(MicroOverview::class)->for($portfolio)['focus'])->keyBy('symbol');
        $this->assertSame([true, true, true, true, true], array_column($focus['DOGEUSDT']['checks'], 'ok'));
        $this->assertSame([true, true, true, true, false], array_column($focus['XRPUSDT']['checks'], 'ok'));

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->has('micro.focus.0.checks', 5)
            ->where('micro.rule', 'scalp_uptrend')
            ->where('micro.rules.2.value', 'scalp_uptrend'));

        Artisan::call('micro:run', ['--explain' => true]);
        $output = Artisan::output();

        foreach (['Atual + alta em 24 h e 4 h', 'DOGEUSDT', 'ENTRA', 'XRPUSDT', 'Sem alta em 24 h e 4 h'] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_existing_wallets_keep_the_current_rule_and_a_real_one_takes_this_rule_only_after_it_passes()
    {
        $this->assertSame(MicroRule::Scalp, Portfolio::factory()->create()->fresh()->micro_rule);

        $payload = ['micro_enabled' => '0', 'micro_order_value' => '5', 'micro_net_margin_pct' => '0.2', 'micro_rule' => 'scalp_uptrend'];

        $paper = Portfolio::factory()->create();
        $this->actingAs($paper->user)->put(route('portfolios.micro', $paper), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::ScalpUptrend, $paper->fresh()->micro_rule);

        $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasErrors('micro_rule');

        MicroBacktest::create(['rule' => MicroRule::ScalpUptrend, 'params' => ['days' => 30], 'summary' => ['validation' => ['trades' => 140, 'mean_pct' => 0.05, 'win_rate' => 51.0], 'failures' => []], 'passed' => true]);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::ScalpUptrend, $live->fresh()->micro_rule);

        $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $live), $payload)->assertForbidden();
    }

    public function test_the_backtest_runs_the_rule_by_name()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->day(98.0));

        $this->artisan('micro:backtest', ['--rule' => 'scalp_uptrend', '--days' => 2, '--save' => true])
            ->expectsOutputToContain('Atual + alta em 24 h e 4 h')
            ->assertSuccessful();

        $this->assertNotNull(MicroBacktest::latestFor(MicroRule::ScalpUptrend));
    }
}
