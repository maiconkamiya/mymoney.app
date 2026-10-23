<?php

namespace Tests\Feature\Trading;

use App\Enums\MicroRule;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\MicroBacktest;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Micro\MicroBacktester;
use App\Trading\Micro\MicroOverview;
use App\Trading\Micro\MicroRanker;
use App\Trading\Micro\MicroRules;
use App\Trading\Micro\MicroWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\TestCase;

class MicroRegraSemanaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Five days of flat 1-minute candles at `$level`, each `$range` tall, then
     * the current rule's entry picture in the last two hours (flat at 100, one
     * spike to 100.95, a slow climb to 100.15).
     *
     * @return list<array{high: float, low: float, close: float}>
     */
    private function week(float $level = 99.5, float $range = 0.1): array
    {
        $candles = [];

        for ($i = 0; $i < 7200; $i++) {
            $candles[] = ['high' => $level + $range / 2, 'low' => $level - $range / 2, 'close' => $level];
        }

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + 0.15 * ($i - 94) / 25;
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

    public function test_enters_on_the_current_picture_above_the_week_with_room_below_its_high()
    {
        $rules = app(MicroRules::class);
        $this->assertSame(120, $rules->lookbackMinutes(MicroRule::ScalpWeek));

        $setup = $rules->read(MicroRule::ScalpWeek, $this->week(), 0.5);

        $this->assertTrue($setup->entry);
        $this->assertSame(['trend', 'support', 'reach', 'room', 'week_trend', 'week_room'], array_column($setup->checks, 'key'));
        $this->assertSame([true, true, true, true, true, true], array_column($setup->checks, 'ok'));

        // The reach reads the week's average 1-minute range, not the one of the last 30 candles.
        $week = MicroWeek::fromCandles($this->week());
        $this->assertEqualsWithDelta($week->rangePct * sqrt(60), $setup->reachPct, 0.0001);
        $this->assertStringContainsString('faixa média de 1 minuto da semana', $setup->checks[2]['detail']);
    }

    public function test_a_price_under_the_week_mean_blocks_it()
    {
        $setup = app(MicroRules::class)->read(MicroRule::ScalpWeek, $this->week(101.0), 0.5);

        $this->assertTrue(app(MicroRules::class)->read(MicroRule::Scalp, array_slice($this->week(101.0), -120), 0.5)->entry);
        $this->assertFalse($setup->entry);
        $this->assertSame([true, true, true, true, false, true], array_column($setup->checks, 'ok'));
        $this->assertStringStartsWith('Abaixo da média da semana', $setup->reasons[0]);
    }

    public function test_a_quiet_week_makes_the_target_out_of_reach()
    {
        $candles = $this->week(99.5, 0.002);
        $rules = app(MicroRules::class);

        // The last two hours alone reach the target; the week does not.
        $this->assertTrue($rules->read(MicroRule::Scalp, array_slice($candles, -120), 0.5)->entry);

        $setup = $rules->read(MicroRule::ScalpWeek, $candles, 0.5);
        $this->assertFalse($setup->entry);
        $this->assertFalse($setup->checks[2]['ok']);
        $this->assertStringStartsWith('Alvo bruto de 0.50% grande demais', $setup->reasons[0]);
    }

    public function test_the_week_high_too_close_blocks_it()
    {
        $candles = $this->week();
        $candles[7280]['high'] = $candles[7280]['close'] + 0.05;

        $setup = app(MicroRules::class)->read(MicroRule::ScalpWeek, $candles, 0.5);

        $this->assertFalse($setup->entry);
        $this->assertFalse(collect($setup->checks)->firstWhere('key', 'week_room')['ok']);
    }

    public function test_less_than_five_days_of_candles_gives_no_reading()
    {
        $this->assertNull(app(MicroRules::class)->read(MicroRule::ScalpWeek, array_slice($this->week(), -7199), 0.5));
    }

    public function test_the_backtest_week_is_the_last_7_days_of_candles_even_with_gaps()
    {
        $candles = [];

        foreach ([...$this->week(), ...$this->week(98.0)] as $i => $candle) {
            $candles[] = ['t' => $i * 60 + ($i >= 2000 ? 3000 * 60 : 0), 'open' => $candle['close']] + $candle;
        }

        $weeks = (new ReflectionMethod(MicroBacktester::class, 'weeks'))->invoke(app(MicroBacktester::class), $candles);
        $span = 10080 * 60;

        foreach ([500, 7319, 10000, 14639] as $t) {
            $inside = array_values(array_filter(array_slice($candles, 0, $t + 1), fn (array $c) => $c['t'] > $candles[$t]['t'] - $span));
            $expected = MicroWeek::fromCandles($inside);
            $week = $weeks($t);

            $this->assertSame($expected->candles, $week->candles);
            $this->assertEqualsWithDelta($expected->averageClose, $week->averageClose, 1e-9);
            $this->assertEqualsWithDelta($expected->high, $week->high, 1e-9);
            $this->assertEqualsWithDelta($expected->rangePct, $week->rangePct, 1e-9);
        }

        $this->assertSame(10080, $weeks(14639)->candles);
        $this->assertSame(7080, $weeks(7319)->candles);
    }

    public function test_the_card_lists_the_rule_explain_reads_it_and_other_users_get_403()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->week());
        $portfolio = Portfolio::factory()->create(['micro_enabled' => true, 'micro_entry_mode' => 'market', 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2, 'micro_rule' => MicroRule::ScalpWeek]);

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();

        $focus = collect(app(MicroOverview::class)->for($portfolio)['focus'])->keyBy('symbol');
        $this->assertSame([true, true, true, true, true, true], array_column($focus['DOGEUSDT']['checks'], 'ok'));

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.rule', 'scalp_week')
            ->where('micro.rules.7.value', 'scalp_week')
            ->where('micro.rules.7.label', 'Atual + semana (7 dias)'));

        Artisan::call('micro:run', ['--explain' => true]);
        $output = Artisan::output();

        foreach (['Atual + semana (7 dias)', 'DOGEUSDT', 'ENTRA'] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_the_engine_says_how_many_candles_a_short_week_has()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), array_slice($this->week(), -3000));
        $portfolio = Portfolio::factory()->create(['micro_enabled' => true, 'micro_rule' => MicroRule::ScalpWeek]);

        $skipped = app(MicroRanker::class)->evaluate($portfolio, 0.5)['skipped'];

        $this->assertSame('DOGEUSDT', $skipped[0]['symbol']);
        $this->assertStringContainsString('7.200 velas de 1 minuto nos últimos 7 dias; este par tem 3.000', $skipped[0]['reason']);
    }

    public function test_the_pairs_in_focus_are_ordered_by_the_week()
    {
        // DOGE moves more in the last minutes, SOL in the week.
        $doge = $this->week(99.5, 0.03);
        $sol = $this->week(99.5, 0.2);

        foreach (array_keys($doge) as $i) {
            if ($i >= 7290) {
                $doge[$i]['high'] += 0.3;
                $doge[$i]['low'] -= 0.3;
            }
        }

        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $doge);
        $this->store(Asset::factory()->create(['symbol' => 'SOLUSDT']), $sol);

        $week = Portfolio::factory()->create(['micro_enabled' => true, 'micro_rule' => MicroRule::ScalpWeek]);
        $current = Portfolio::factory()->create(['micro_enabled' => true, 'micro_rule' => MicroRule::Scalp]);

        $this->assertSame(['SOLUSDT', 'DOGEUSDT'], app(MicroRanker::class)->evaluate($week, 0.5)['ready']->pluck('asset.symbol')->all());
        $this->assertSame(['DOGEUSDT', 'SOLUSDT'], app(MicroRanker::class)->evaluate($current, 0.5)['ready']->pluck('asset.symbol')->all());
    }

    public function test_a_real_wallet_takes_this_rule_only_after_it_passes()
    {
        $payload = ['micro_enabled' => '0', 'micro_order_value' => '5', 'micro_net_margin_pct' => '0.2', 'micro_rule' => 'scalp_week'];

        $paper = Portfolio::factory()->create();
        $this->actingAs($paper->user)->put(route('portfolios.micro', $paper), $payload)->assertSessionHasNoErrors();
        $this->assertSame(MicroRule::ScalpWeek, $paper->fresh()->micro_rule);

        $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), $payload)->assertSessionHasErrors('micro_rule');
        $this->assertSame(MicroRule::Scalp, $live->fresh()->micro_rule);

        $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $paper), ['micro_rule' => 'scalp'] + $payload)->assertForbidden();
        $this->assertSame(MicroRule::ScalpWeek, $paper->fresh()->micro_rule);
    }

    public function test_the_backtest_runs_the_rule_by_name()
    {
        $this->store(Asset::factory()->create(['symbol' => 'DOGEUSDT']), $this->week());

        $this->artisan('micro:backtest', ['--rule' => 'scalp_week', '--days' => 7, '--save' => true])
            ->expectsOutputToContain('Atual + semana (7 dias)')
            ->assertSuccessful();

        $this->assertNotNull(MicroBacktest::latestFor(MicroRule::ScalpWeek));
    }
}
