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

class MicroFilteredRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * 1450 one-minute candles: a day climbing from 98 to 100, then the current
     * rule's entry picture (flat, one spike, a climb to 100.15). Every other
     * minute of the climb dips `$dip`, so the RSI is not pinned at 100, and
     * the last 5 minutes trade `$lastVolume` against 1 before.
     *
     * @return list<array{high: float, low: float, close: float, volume: float}>
     */
    private function day(float $dip = 0.03, float $lastVolume = 3.0): array
    {
        $candles = [];

        for ($i = 0; $i < 1330; $i++) {
            $close = 98.0 + 2.0 * $i / 1330;
            $candles[] = ['high' => $close + 0.05, 'low' => $close - 0.05, 'close' => $close, 'volume' => 1.0];
        }

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + 0.15 * ($i - 94) / 25;
            $close -= $i >= 95 && $i < 119 && $i % 2 === 0 ? $dip : 0.0;
            $candles[] = ['high' => $close + 0.05 + ($i === 80 ? 0.9 : 0), 'low' => $close - 0.05, 'close' => $close, 'volume' => $i >= 115 ? $lastVolume : 1.0];
        }

        return $candles;
    }

    private function store(Asset $asset, array $candles): void
    {
        $rows = [];
        $last = count($candles) - 1;

        foreach ($candles as $i => $candle) {
            $rows[] = ['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute()->subMinutes($last - $i), 'open' => $candle['close'], 'high' => $candle['high'], 'low' => $candle['low'], 'close' => $candle['close'], 'volume' => $candle['volume']];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Candle::insert($chunk);
        }
    }

    public function test_enters_only_with_the_rsi_under_70_and_the_volume_above_the_average()
    {
        $rules = app(MicroRules::class);

        $ready = $rules->read(MicroRule::ScalpFiltered, $this->day(), 0.5);
        $this->assertTrue($ready->entry);
        $this->assertSame(['trend', 'support', 'reach', 'room', 'uptrend', 'rsi', 'volume'], array_column($ready->checks, 'key'));
        $this->assertStringContainsString('RSI em 62 e volume 3.00 vezes a média', implode(' ', $ready->reasons));

        // A straight climb: the 24 h and 4 h rule enters, the RSI blocks this one.
        $stretched = $rules->read(MicroRule::ScalpFiltered, $this->day(dip: 0.012), 0.5);
        $this->assertTrue($rules->read(MicroRule::ScalpUptrend, $this->day(dip: 0.012), 0.5)->entry);
        $this->assertFalse($stretched->entry);
        $this->assertFalse($stretched->checks[5]['ok']);
        $this->assertSame(['RSI em 77: preço esticado (máximo 70).'], $stretched->reasons);

        // Volume at the average is not above it.
        $quiet = $rules->read(MicroRule::ScalpFiltered, $this->day(lastVolume: 1.0), 0.5);
        $this->assertFalse($quiet->entry);
        $this->assertTrue($quiet->checks[5]['ok']);
        $this->assertFalse($quiet->checks[6]['ok']);
        $this->assertStringStartsWith('Volume fraco: 1.00 vezes a média', $quiet->reasons[0]);

        $this->assertNull($rules->read(MicroRule::ScalpFiltered, array_slice($this->day(), -1000), 0.5));
    }

    public function test_the_focus_cards_show_the_filters_explain_gives_the_reason_and_other_users_get_403()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->day());
        $this->store(Asset::factory()->create(['symbol' => 'XRPUSDT']), $this->day(lastVolume: 1.0));
        $portfolio = Portfolio::factory()->create(['micro_enabled' => true, 'micro_entry_mode' => 'market', 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2, 'micro_rule' => MicroRule::ScalpFiltered]);

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();

        $focus = collect(app(MicroOverview::class)->for($portfolio)['focus'])->keyBy('symbol');
        $this->assertSame(array_fill(0, 7, true), array_column($focus['DOGEUSDT']['checks'], 'ok'));
        $this->assertFalse($focus['XRPUSDT']['checks'][6]['ok']);

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->has('micro.focus.0.checks', 7)
            ->where('micro.rule', 'scalp_filtered')
            ->where('micro.rules.5.value', 'scalp_filtered'));

        Artisan::call('micro:run', ['--explain' => true]);
        $output = Artisan::output();

        foreach (['Atual + alta + RSI e volume', 'DOGEUSDT', 'ENTRA', 'XRPUSDT', 'Volume fraco'] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_a_real_wallet_takes_this_rule_only_after_it_passes_the_backtest()
    {
        $payload = ['micro_enabled' => '0', 'micro_order_value' => '5', 'micro_net_margin_pct' => '0.2', 'micro_rule' => 'scalp_filtered'];

        $paper = Portfolio::factory()->create();
        $this->actingAs($paper->user)->put(route('portfolios.micro', $paper), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::ScalpFiltered, $paper->fresh()->micro_rule);

        $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasErrors('micro_rule');
        $this->assertSame(MicroRule::Scalp, $live->fresh()->micro_rule);

        MicroBacktest::create(['rule' => MicroRule::ScalpFiltered, 'params' => ['days' => 30], 'summary' => ['validation' => ['trades' => 140, 'mean_pct' => 0.05, 'win_rate' => 51.0], 'failures' => []], 'passed' => true]);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::ScalpFiltered, $live->fresh()->micro_rule);

        $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $live), $payload)->assertForbidden();
    }

    public function test_the_backtest_runs_the_rule_by_name()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->day());

        $this->artisan('micro:backtest', ['--rule' => 'scalp_filtered', '--days' => 2, '--save' => true])
            ->expectsOutputToContain('Atual + alta + RSI e volume')
            ->assertSuccessful();

        $this->assertNotNull(MicroBacktest::latestFor(MicroRule::ScalpFiltered));
    }
}
