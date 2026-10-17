<?php

namespace Tests\Feature\Trading;

use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Candle;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Micro\MicroCosts;
use App\Trading\Micro\MicroTrader;
use App\Trading\PositionExits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MicroOperationsTest extends TestCase
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
                'high' => $close + 0.01 + ($i === 80 ? 0.9 : 0),
                'low' => $close - 0.01,
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
            'micro_order_value' => 20,
            'micro_net_margin_pct' => 0.2,
        ]);
    }

    private function advanceTo(float $price): void
    {
        Candle::where('asset_id', $this->asset->id)->latest('open_at')->first()->update(['open' => $price, 'high' => $price, 'low' => $price, 'close' => $price]);
    }

    public function test_costs_add_fees_and_slippage_to_the_net_margin()
    {
        $costs = new MicroCosts;

        $this->assertEqualsWithDelta(0.3, $costs->roundTripPct(), 0.0001);
        $this->assertEqualsWithDelta(0.5, $costs->grossMarginPct(0.2), 0.0001);
    }

    public function test_opens_a_cycle_with_target_and_stop_and_closes_it_at_the_target_with_net_profit()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $cycle = MicroCycle::sole();
        $position = $portfolio->openPositions()->sole();
        $this->assertSame('open', $cycle->status);
        $this->assertEqualsWithDelta($cycle->entry_price * 1.005, $cycle->target_price, 0.0001);
        $this->assertEqualsWithDelta($cycle->entry_price * 0.995, $cycle->stop_price, 0.0001);
        $this->assertEqualsWithDelta($cycle->target_price, $position->target_price, 0.0001);
        $this->assertEqualsWithDelta(20, $cycle->qty * $cycle->entry_price, 0.5);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.opened', 'subject_id' => $cycle->id]);

        // One cycle per pair at a time.
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));

        $this->advanceTo(101.0);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $cycle->refresh();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('target', $cycle->exit_reason);
        $this->assertGreaterThan(0, $cycle->net_pnl);
        $this->assertGreaterThan($cycle->fees, $cycle->qty * ($cycle->exit_price - $cycle->entry_price));
        $this->assertEqualsWithDelta($cycle->qty * ($cycle->exit_price - $cycle->entry_price) - $cycle->fees, $cycle->net_pnl, 0.000001);
    }

    public function test_a_stop_books_a_loss_and_pauses_that_pair()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $this->advanceTo(99.0);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $cycle = MicroCycle::sole();
        $this->assertSame('stop', $cycle->exit_reason);
        $this->assertLessThan(0, $cycle->net_pnl);

        // Chart back to an entry setup right after the stop: the cooldown holds the pair.
        Candle::where('asset_id', $this->asset->id)->delete();
        $this->candles($this->asset);
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
    }

    public function test_a_cycle_held_too_long_is_sold()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        MicroCycle::query()->update(['opened_at' => now()->subHours(2)]);

        app(MicroTrader::class)->run($portfolio);

        $cycle = MicroCycle::oldest('id')->first();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('micro_time', $cycle->exit_reason);
    }

    public function test_stops_for_the_day_after_the_target_or_the_loss_limit()
    {
        $this->candles($this->asset);
        $target = $this->micro(['micro_daily_target' => 1]);
        MicroCycle::create(['portfolio_id' => $target->id, 'asset_id' => $this->asset->id, 'status' => 'closed', 'qty' => 1, 'entry_price' => 1, 'target_price' => 1, 'stop_price' => 1, 'net_pnl' => 1.5, 'gross_margin_pct' => 0.5, 'opened_at' => now(), 'closed_at' => now()]);

        $this->assertSame('target_hit', app(MicroTrader::class)->state($target)['code']);
        $this->assertSame(0, app(MicroTrader::class)->run($target));

        $loss = $this->micro(['micro_daily_loss_limit' => 2]);
        MicroCycle::create(['portfolio_id' => $loss->id, 'asset_id' => $this->asset->id, 'status' => 'closed', 'qty' => 1, 'entry_price' => 1, 'target_price' => 1, 'stop_price' => 1, 'net_pnl' => -2.5, 'gross_margin_pct' => 0.5, 'opened_at' => now(), 'closed_at' => now()]);

        $this->assertSame('loss_limit', app(MicroTrader::class)->state($loss)['code']);
        $this->assertSame(0, app(MicroTrader::class)->run($loss));
        $this->assertSame(0, Order::where('portfolio_id', $loss->id)->count());
    }

    public function test_does_nothing_when_off_or_when_the_chart_gives_no_entry()
    {
        $this->candles($this->asset, 101.0);

        $this->assertSame(0, app(MicroTrader::class)->run($this->micro()));
        $this->assertSame(0, app(MicroTrader::class)->run($this->micro(['micro_enabled' => false])));
        $this->assertSame(0, Order::count());
    }

    public function test_real_portfolio_without_confirmed_auto_execution_sends_nothing()
    {
        config(['trading.live.enabled' => true]);
        $this->candles($this->asset);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);
        $portfolio = $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id]);

        $this->assertSame('live_not_confirmed', app(MicroTrader::class)->state($portfolio)['code']);
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertSame(0, Order::count());
    }

    public function test_the_command_runs_every_enabled_crypto_portfolio()
    {
        $this->candles($this->asset);
        $this->micro();
        $this->micro(['micro_enabled' => false]);

        $this->artisan('micro:run')->assertSuccessful();

        $this->assertSame(1, MicroCycle::count());
        $this->assertSame(OrderSide::Buy, Order::sole()->side);
        $this->assertSame(OrderStatus::Filled, Order::sole()->status);
    }

    public function test_owner_saves_the_settings_and_the_page_shows_the_mode()
    {
        $portfolio = $this->micro(['micro_enabled' => false]);
        $owner = $portfolio->user;

        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), [
            'micro_enabled' => '1',
            'micro_order_value' => '25',
            'micro_net_margin_pct' => '0.3',
            'micro_daily_target' => '2',
            'micro_daily_loss_limit' => '1',
        ])->assertRedirect();

        $portfolio->refresh();
        $this->assertTrue($portfolio->micro_enabled);
        $this->assertEquals(25, $portfolio->micro_order_value);
        $this->assertEquals(2, $portfolio->micro_daily_target);
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.micro_settings', 'subject_id' => $portfolio->id]);

        $this->actingAs($owner)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.enabled', true)
            ->where('micro.gross_margin_pct', 0.6)
            ->where('micro.state.code', 'active'));
    }

    public function test_validation_and_limits_on_the_settings()
    {
        $portfolio = $this->micro(['micro_enabled' => false]);
        $owner = $portfolio->user;

        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), ['micro_enabled' => '1', 'micro_order_value' => '1', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasErrors('micro_order_value');

        $b3 = Portfolio::factory()->create(['market' => Market::B3]);
        $this->actingAs($b3->user)->put(route('portfolios.micro', $b3), ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasErrors('micro_enabled');

        $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasErrors('micro_enabled');
        $this->assertFalse($live->fresh()->micro_enabled);
    }

    public function test_other_users_get_403_on_the_settings()
    {
        $portfolio = $this->micro(['micro_enabled' => false]);

        $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $portfolio), ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2'])
            ->assertForbidden();
        $this->assertFalse($portfolio->fresh()->micro_enabled);
    }
}
