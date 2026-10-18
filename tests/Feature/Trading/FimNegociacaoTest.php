<?php

namespace Tests\Feature\Trading;

use App\Agents\SignalScanner;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Candle;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Data\OrderRequest;
use App\Trading\Micro\MicroRanker;
use App\Trading\Micro\MicroTrader;
use App\Trading\PositionExits;
use App\Trading\Risk\RiskGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fim de negociação de um par: a partir da 00:00 UTC da data o app não compra
 * mais e vende a mercado o que houver em cada carteira.
 */
class FimNegociacaoTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 23:58:00');
        Http::preventStrayRequests();
        $this->asset = Asset::factory()->create(['symbol' => 'DOGEUSDT']);
    }

    /**
     * 120 one-minute candles ending now: flat, one spike, then a slow climb near the support.
     */
    private function candles(float $end = 100.15): void
    {
        $rows = [];

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + ($end - 100.0) * ($i - 94) / 25;
            $rows[] = [
                'asset_id' => $this->asset->id,
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
            'micro_order_value' => 20,
            'micro_net_margin_pct' => 0.2,
        ]);
    }

    /**
     * Passa para o dia da data fim, com a cotação ainda fresca.
     */
    private function enterEndDay(float $price = 100.1): void
    {
        $this->asset->update(['trading_ends_on' => '2026-10-20']);
        Carbon::setTestNow('2026-10-20 00:00:30');
        Candle::create(['asset_id' => $this->asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute(), 'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price, 'volume' => 1]);
    }

    private function liveMicro(string &$sellState): Portfolio
    {
        config(['trading.live.enabled' => true]);
        $this->asset->update(['lot_size' => '0.0001', 'tick_size' => '0.01', 'min_qty' => '0.0001', 'min_notional' => '5']);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);

        Http::fake(function (Request $request) use (&$sellState) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $url = $request->url();

            return match (true) {
                $path === '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
                $path === '/api/v3/ticker/price' => Http::response([]),
                $path === '/api/v3/account' => Http::response(['balances' => [['asset' => 'DOGE', 'free' => '0.19970001', 'locked' => '0'], ['asset' => 'USDT', 'free' => '30', 'locked' => '0']]]),
                $request->method() === 'POST' && str_contains($url, 'type=LIMIT') => Http::response(['orderId' => 2, 'status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0', 'fills' => []]),
                $request->method() === 'POST' => Http::response(['orderId' => str_contains($url, 'side=BUY') ? 1 : 3, 'status' => 'FILLED', 'executedQty' => '0.1999', 'cummulativeQuoteQty' => '20.02', 'transactTime' => now()->getTimestampMs(), 'fills' => [['price' => '100.15', 'qty' => '0.1999', 'commission' => str_contains($url, 'side=BUY') ? '0.0002' : '0.02', 'commissionAsset' => str_contains($url, 'side=BUY') ? 'DOGE' : 'USDT']]]),
                $request->method() === 'DELETE' => Http::response(['orderId' => 2, 'status' => $sellState = 'CANCELED']),
                str_contains($url, 'orderId=2') => Http::response(['orderId' => 2, 'status' => $sellState, 'executedQty' => '0', 'cummulativeQuoteQty' => '0', 'transactTime' => now()->getTimestampMs()]),
                default => Http::response(['orderId' => 1, 'status' => 'FILLED', 'executedQty' => '0.1999', 'cummulativeQuoteQty' => '20.02']),
            };
        });

        return $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id, 'auto_execute' => true, 'live_auto_confirmed_at' => now(), 'cash' => 50, 'initial_cash' => 50]);
    }

    public function test_the_trading_ends_at_midnight_utc_of_the_end_date()
    {
        $this->asset->update(['trading_ends_on' => '2026-10-20']);

        $this->assertFalse($this->asset->fresh()->tradingEnded());
        $this->assertSame(1, Asset::query()->tradable()->count());

        Carbon::setTestNow('2026-10-20 00:00:00');

        $this->assertTrue($this->asset->fresh()->tradingEnded());
        $this->assertSame(0, Asset::query()->tradable()->count());
    }

    public function test_the_risk_guard_blocks_buys_from_the_end_date_and_still_lets_sells_out()
    {
        $this->candles();
        $portfolio = $this->micro();
        $buy = fn () => new OrderRequest($this->asset->fresh(), OrderSide::Buy, 0.1, stopPrice: 95);

        $this->assertTrue(app(RiskGuard::class)->check($portfolio, $buy())->allowed);

        $portfolio->positions()->create(['asset_id' => $this->asset->id, 'qty' => 0.2, 'avg_price' => 100, 'opened_at' => now()]);
        $this->enterEndDay();

        $decision = app(RiskGuard::class)->check($portfolio, $buy());
        $this->assertFalse($decision->allowed);
        $this->assertSame(['Negociação de DOGEUSDT encerrada em 20/10/2026: o app só vende.'], $decision->reasons);

        $this->assertTrue(app(RiskGuard::class)->check($portfolio, new OrderRequest($this->asset->fresh(), OrderSide::Sell, 0.2))->allowed);
    }

    public function test_micro_operations_and_the_agents_leave_the_pair_alone()
    {
        $this->candles();
        $portfolio = $this->micro();
        $this->enterEndDay(100.15);

        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertSame(0, Order::count());
        $this->assertCount(0, app(MicroRanker::class)->rank($portfolio, 0.5));
        $this->assertSame([], app(SignalScanner::class)->scan($this->asset->fresh()));
    }

    public function test_a_simulated_position_with_a_resting_sale_is_sold_at_market_on_the_end_date()
    {
        $this->candles();
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        $limit = Order::where('side', OrderSide::Sell)->sole();
        $this->assertSame(OrderStatus::Pending, $limit->status);

        // The day before nothing changes.
        app(PositionExits::class)->checkStopsAndTargets();
        $this->assertSame(OrderStatus::Pending, $limit->fresh()->status);

        $this->enterEndDay();

        $this->assertSame(1, app(PositionExits::class)->checkStopsAndTargets());

        $this->assertSame(OrderStatus::Cancelled, $limit->fresh()->status);
        $market = Order::where('side', OrderSide::Sell)->where('type', OrderType::Market)->sole();
        $this->assertSame(OrderStatus::Filled, $market->status);
        $this->assertSame('delisting', $market->reason);
        $this->assertSame('delisting', $portfolio->positions()->sole()->exit_reason);

        // The cycle closes with the same reason, and micro operations put no new sale up.
        app(MicroTrader::class)->run($portfolio);
        $this->assertSame('delisting', MicroCycle::sole()->exit_reason);
        $this->assertSame(0, Order::where('status', OrderStatus::Pending)->count());
    }

    public function test_a_real_position_cancels_the_limit_sale_on_binance_and_sells_at_market()
    {
        $this->candles();
        $sellState = 'NEW';
        $portfolio = $this->liveMicro($sellState);
        app(MicroTrader::class)->run($portfolio);

        $this->enterEndDay();
        $this->assertSame(1, app(PositionExits::class)->checkStopsAndTargets());

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'orderId=2'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'side=SELL') && str_contains($request->url(), 'type=MARKET'));
        $this->assertSame('delisting', Order::where('side', OrderSide::Sell)->where('type', OrderType::Market)->sole()->reason);
        $this->assertNotNull($portfolio->positions()->sole()->closed_at);
    }

    public function test_a_balance_under_the_pair_minimum_stays_and_the_portfolio_shows_why()
    {
        $this->candles();
        $sellState = 'NEW';
        $portfolio = $this->liveMicro($sellState);
        app(MicroTrader::class)->run($portfolio);
        $this->asset->update(['min_notional' => '50']);

        $this->enterEndDay();
        $this->assertSame(0, app(PositionExits::class)->checkStopsAndTargets());

        $position = $portfolio->openPositions()->sole();
        $this->assertStringContainsString('abaixo do mínimo de 50.00 USDT', (string) PositionExits::delistingBlock($position));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'side=SELL') && str_contains($request->url(), 'type=MARKET'));

        $this->actingAs($portfolio->user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->where('positions.0.trading_ends_on', '2026-10-20')
                ->where('positions.0.trading_ended', true)
                ->where('positions.0.delisting_block', fn ($reason) => str_contains((string) $reason, 'abaixo do mínimo'))
                ->where('available_assets.0.trading_ended', true));
    }

    public function test_only_the_administrator_sets_the_end_date_and_others_get_403()
    {
        config(['app.admin_emails' => []]);
        $admin = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->put(route('assets.trading-end', $this->asset), ['trading_ends_on' => '2026-10-25'])
            ->assertForbidden();
        $this->assertNull($this->asset->fresh()->trading_ends_on);

        $this->actingAs($other)
            ->get(route('assets.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can_edit_trading_end', false));

        $this->actingAs($admin)
            ->put(route('assets.trading-end', $this->asset), ['trading_ends_on' => '25/10/2026'])
            ->assertSessionHasErrors('trading_ends_on');

        $this->actingAs($admin)
            ->put(route('assets.trading-end', $this->asset), ['trading_ends_on' => '2026-10-25'])
            ->assertRedirect();
        $this->assertSame('2026-10-25', $this->asset->fresh()->trading_ends_on?->toDateString());
        $this->assertDatabaseHas('audit_log', ['action' => 'asset.trading_end_updated', 'subject_id' => $this->asset->id]);

        $this->actingAs($admin)
            ->get(route('assets.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_edit_trading_end', true)
                ->where('assets.0.trading_ends_on', '2026-10-25')
                ->where('assets.0.trading_ended', false));

        $this->actingAs($admin)
            ->put(route('assets.trading-end', $this->asset), ['trading_ends_on' => null])
            ->assertRedirect();
        $this->assertNull($this->asset->fresh()->trading_ends_on);
    }
}
