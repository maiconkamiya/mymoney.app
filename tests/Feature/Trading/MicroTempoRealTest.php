<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\Candle;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Micro\MicroTrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MicroTempoRealTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->asset = Asset::factory()->create(['symbol' => 'DOGEUSDT']);
    }

    /**
     * 120 one-minute candles ending now: flat, one spike, then a slow climb near the support.
     */
    private function candles(Asset $asset, float $end = 100.15): void
    {
        $rows = [];

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + ($end - 100.0) * ($i - 94) / 25;
            $rows[] = [
                'asset_id' => $asset->id,
                'timeframe' => '1m',
                'open_at' => now()->startOfMinute()->subMinutes(119 - $i),
                'open' => $close,
                'high' => $close + 0.05 + ($i === 80 ? 0.9 : 0),
                'low' => $close - 0.05,
                'close' => $close,
                'volume' => 1,
            ];
        }

        Candle::insert($rows);
    }

    private function micro(array $attributes = []): Portfolio
    {
        return Portfolio::factory()->create($attributes + [
            'micro_enabled' => true,
            // A compra a mercado: estes testes cobrem o ciclo depois da compra; a limitada tem os seus em MicroPrecoSugeridoTest.
            'micro_entry_mode' => 'market',
            'micro_order_value' => 20,
            'micro_net_margin_pct' => 0.2,
        ]);
    }

    private function advanceTo(float $price): void
    {
        Candle::where('asset_id', $this->asset->id)->latest('open_at')->first()->update(['open' => $price, 'high' => $price, 'low' => $price, 'close' => $price]);
    }

    public function test_the_watcher_opens_a_cycle_and_the_minute_run_becomes_the_reserve()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();

        $this->artisan('micro:watch --once')->assertSuccessful();

        $this->assertSame(1, MicroCycle::where('portfolio_id', $portfolio->id)->count());
        $this->assertTrue(MicroTrader::realtimeAlive());

        // With the watcher alive the minute run sends nothing, even for a portfolio the watcher has not seen yet.
        $other = $this->micro();
        Artisan::call('micro:run');
        $this->assertStringContainsString('Motor em tempo real ativo', Artisan::output());
        $this->assertSame(0, Order::where('portfolio_id', $other->id)->count());

        // Watcher gone for longer than the window: the minute run takes over.
        $this->travel(31)->seconds();
        $this->assertFalse(MicroTrader::realtimeAlive());
        Artisan::call('micro:run');
        $this->assertSame(1, MicroCycle::where('portfolio_id', $other->id)->count());
    }

    public function test_the_stop_is_sold_on_the_next_pass_without_waiting_for_the_minute()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $this->advanceTo(99.0);
        $this->assertSame([$portfolio->openPositions()->sole()->id], app(MicroTrader::class)->due($portfolio));

        $this->artisan('micro:watch --once')->assertSuccessful();

        $cycle = MicroCycle::sole();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('stop', $cycle->exit_reason);
        $this->assertLessThan(0, $cycle->net_pnl);
        $this->assertSame([], app(MicroTrader::class)->due($portfolio));
    }

    public function test_the_target_closes_the_cycle_and_frees_the_pair_on_the_same_pass()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $this->advanceTo(101.0);
        $this->artisan('micro:watch --once')->assertSuccessful();

        $cycle = MicroCycle::oldest('id')->first();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('target', $cycle->exit_reason);
        $this->assertGreaterThan(0, $cycle->net_pnl);
    }

    public function test_a_portfolio_held_by_another_engine_is_left_alone()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $lock = Cache::lock('trading:portfolio:'.$portfolio->id, 120);
        $this->assertTrue($lock->get());

        $this->artisan('micro:watch --once')->assertSuccessful();
        $this->assertSame(0, Order::count());

        $lock->release();
        $this->artisan('micro:watch --once')->assertSuccessful();
        $this->assertSame(1, MicroCycle::count());
    }

    public function test_the_card_says_whether_the_engine_runs_in_real_time()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.realtime', false));

        MicroTrader::markRealtime();

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.realtime', true));

        // Another account sees neither the card nor the sale button.
        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get(route('portfolios.show', $portfolio))->assertForbidden();
        $this->actingAs($intruder)->post(route('portfolios.liquidate-now', $portfolio), ['asset_id' => $this->asset->id])->assertForbidden();
        $this->assertSame(1, $portfolio->openPositions()->count());
    }

    public function test_liquidate_now_still_sells_through_the_lock()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $this->actingAs($portfolio->user)->post(route('portfolios.liquidate-now', $portfolio), ['asset_id' => $this->asset->id])->assertRedirect();

        $this->assertSame(0, $portfolio->openPositions()->count());
    }
}
