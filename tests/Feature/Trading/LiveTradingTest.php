<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\PortfolioMode;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\BrokerCredential;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use App\Trading\BrokerManager;
use App\Trading\Brokers\BinanceBroker;
use App\Trading\Data\OrderRequest;
use App\Trading\Gateways\Mt5HttpGateway;
use App\Trading\Live\CredentialVerifier;
use App\Trading\Live\LiveTradingException;
use App\Trading\Live\OrderReconciler;
use App\Trading\OrderExecutor;
use App\Trading\TradingHalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class LiveTradingTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trading.live.max_order_value' => 20, 'trading.live.max_daily_value' => 30]);
        Http::preventStrayRequests();

        $this->asset = Asset::factory()->create(['symbol' => 'BTCUSDT', 'lot_size' => '0.00001']);
        $this->quote($this->asset, 100);
    }

    private function credential(array $attributes = []): BrokerCredential
    {
        return BrokerCredential::create($attributes + [
            'user_id' => User::factory()->create()->id,
            'broker' => 'binance',
            'label' => 'teste',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'can_withdraw' => false,
            'verified_at' => now(),
        ]);
    }

    private function livePortfolio(?BrokerCredential $credential = null, array $attributes = []): Portfolio
    {
        $credential ??= $this->credential();

        return Portfolio::factory()->create($attributes + [
            'user_id' => $credential->user_id,
            'mode' => PortfolioMode::Live,
            'broker' => 'binance',
            'broker_credential_id' => $credential->id,
            'initial_cash' => 1_000,
            'cash' => 1_000,
        ]);
    }

    private function signal(Portfolio $portfolio, string $decidedBy = 'user:1'): Signal
    {
        return Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $this->asset->id,
            'stage' => SignalStage::Confirmed,
            'side' => 'buy',
            'stop_price' => 95,
            'decided_by' => $decidedBy,
        ]);
    }

    private function fakeBinanceFill(): void
    {
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            'api.binance.com/api/v3/order*' => Http::response([
                'orderId' => 77, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20',
                'transactTime' => now()->getTimestampMs(),
                'fills' => [['price' => '100', 'qty' => '0.2', 'commission' => '0.02', 'commissionAsset' => 'USDT']],
            ])]);
    }

    public function test_live_execution_is_off_by_default()
    {
        $this->assertFalse(config('trading.live.enabled'));

        $order = app(OrderExecutor::class)->execute($this->signal($this->livePortfolio()), 'user:1');

        $this->assertNull($order);
        Http::assertNothingSent();
    }

    public function test_approved_order_is_capped_sent_and_booked()
    {
        config(['trading.live.enabled' => true]);
        $this->fakeBinanceFill();
        $portfolio = $this->livePortfolio();

        $order = app(OrderExecutor::class)->execute($this->signal($portfolio), 'user:1');

        $this->assertSame(OrderStatus::Filled, $order->status, (string) $order->reject_reason);
        $this->assertSame('BTCUSDT:77', $order->external_id);
        $this->assertEqualsWithDelta(0.2, $order->qty, 1e-9); // 20 USDT cap at price 100
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('X-MBX-APIKEY', 'key')
            && str_contains($request->url(), 'signature=')
            && str_contains($request->url(), 'quantity=0.2&'));
        $this->assertEqualsWithDelta(980 - 0.02, $portfolio->fresh()->cash, 1e-6);
    }

    public function test_orders_without_a_persons_approval_never_reach_the_broker()
    {
        config(['trading.live.enabled' => true]);
        $portfolio = $this->livePortfolio();

        $order = app(OrderExecutor::class)->execute($this->signal($portfolio, 'auto'), 'auto');

        $this->assertNull($order);
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_daily_cap_blocks_further_orders()
    {
        config(['trading.live.enabled' => true]);
        $this->fakeBinanceFill();
        $portfolio = $this->livePortfolio();

        app(OrderExecutor::class)->execute($this->signal($portfolio), 'user:1'); // 20 of 30
        $second = app(OrderExecutor::class)->execute($this->signal($portfolio), 'user:1');
        $this->assertEqualsWithDelta(0.1, $second->qty, 1e-9); // only 10 left

        $third = app(OrderExecutor::class)->execute($this->signal($portfolio), 'user:1');
        $this->assertNull($third);
    }

    public function test_halt_stops_real_orders()
    {
        config(['trading.live.enabled' => true]);
        app(TradingHalt::class)->halt('teste');

        $order = app(OrderExecutor::class)->execute($this->signal($this->livePortfolio()), 'user:1');

        $this->assertNull($order);
        Http::assertNothingSent();
    }

    public function test_broker_itself_refuses_when_halted_or_credential_is_unsafe()
    {
        config(['trading.live.enabled' => true]);
        $request = new OrderRequest($this->asset, OrderSide::Buy, 0.1);

        $unsafe = $this->livePortfolio($this->credential(['can_withdraw' => true]));
        try {
            app(BinanceBroker::class, ['portfolio' => $unsafe])->placeOrder($request);
            $this->fail('Credencial com saque deveria ser recusada.');
        } catch (LiveTradingException) {
        }

        $unverified = $this->livePortfolio($this->credential(['verified_at' => null]));
        $this->expectException(LiveTradingException::class);
        app(BinanceBroker::class, ['portfolio' => $unverified])->placeOrder($request);
    }

    public function test_broker_refuses_auto_execute_without_the_owners_confirmation()
    {
        config(['trading.live.enabled' => true]);
        $portfolio = $this->livePortfolio(attributes: ['auto_execute' => true]);

        $this->expectException(LiveTradingException::class);
        app(BinanceBroker::class, ['portfolio' => $portfolio])->placeOrder(new OrderRequest($this->asset, OrderSide::Buy, 0.1));
    }

    public function test_verification_accepts_keys_without_withdraw_permission()
    {
        Http::fake(['api.binance.com/*' => Http::response(['enableSpotAndMarginTrading' => true, 'enableWithdrawals' => false])]);
        $credential = $this->credential(['verified_at' => null]);

        app(CredentialVerifier::class)->verify($credential);

        $this->assertFalse($credential->fresh()->can_withdraw);
        $this->assertNotNull($credential->fresh()->verified_at);
    }

    public function test_verification_flags_keys_that_can_withdraw()
    {
        Http::fake(['api.binance.com/*' => Http::response(['enableSpotAndMarginTrading' => true, 'enableWithdrawals' => true])]);
        $credential = $this->credential(['verified_at' => null]);

        app(CredentialVerifier::class)->verify($credential);

        $this->assertTrue($credential->fresh()->can_withdraw);
        $this->assertNull($credential->fresh()->verified_at);
    }

    public function test_credential_secrets_are_encrypted_at_rest()
    {
        $credential = $this->credential();

        $raw = \DB::table('broker_credentials')->where('id', $credential->id)->first();

        $this->assertNotSame('secret', $raw->api_secret);
        $this->assertNotSame('key', $raw->api_key);
    }

    public function test_reconciliation_halts_trading_on_divergence()
    {
        config(['trading.live.enabled' => true]);

        // Http::fake stubs stack and the first match wins, so one stub answers with the exchange's current state.
        $exchange = ['orderId' => 77, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20',
            'transactTime' => now()->getTimestampMs(),
            'fills' => [['price' => '100', 'qty' => '0.2', 'commission' => '0.02', 'commissionAsset' => 'USDT']]];
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            'api.binance.com/api/v3/order*' => function () use (&$exchange) {
                return Http::response($exchange);
            }]);

        $portfolio = $this->livePortfolio();
        $order = app(OrderExecutor::class)->execute($this->signal($portfolio), 'user:1');

        $this->assertSame([], app(OrderReconciler::class)->reconcile());
        $this->assertFalse(app(TradingHalt::class)->isHalted());

        $exchange = ['orderId' => 77, 'status' => 'CANCELED', 'executedQty' => '0', 'cummulativeQuoteQty' => '0'];
        $this->assertCount(1, app(OrderReconciler::class)->reconcile());
        $this->assertTrue(app(TradingHalt::class)->isHalted());
        $this->assertTrue(app(TradingHalt::class)->isLiveOnly());
        $this->assertNotNull($order);
    }

    public function test_reconciliation_timeout_is_retried_before_halting()
    {
        config(['trading.live.enabled' => true, 'trading.live.reconcile_max_failures' => 3]);

        $filled = ['orderId' => 77, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20',
            'transactTime' => now()->getTimestampMs(),
            'fills' => [['price' => '100', 'qty' => '0.2', 'commission' => '0.02', 'commissionAsset' => 'USDT']]];
        $timeout = false;
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            'api.binance.com/api/v3/order*' => function () use (&$filled, &$timeout) {
                if ($timeout) {
                    throw new ConnectionException('cURL error 28: Connection timed out after 10002 ms');
                }

                return Http::response($filled);
            }]);

        $order = app(OrderExecutor::class)->execute($this->signal($this->livePortfolio()), 'user:1');
        $this->assertNotNull($order);
        $reconciler = app(OrderReconciler::class);
        $halt = app(TradingHalt::class);

        // Two timeouts in a row are logged, not a divergence.
        $timeout = true;
        $this->assertSame([], $reconciler->reconcile());
        $this->assertSame([], $reconciler->reconcile());
        $this->assertFalse($halt->isHalted());
        $this->assertSame(2, AuditLog::where('action', 'orders.reconcile_failed')->count());
        $this->assertSame(0, AuditLog::where('action', 'orders.divergence')->count());

        // The exchange answering again resets the count.
        $timeout = false;
        $this->assertSame([], $reconciler->reconcile());

        $timeout = true;
        $this->assertSame([], $reconciler->reconcile());
        $this->assertSame([], $reconciler->reconcile());
        $this->assertFalse($halt->isHalted());

        // The third failure in a row halts.
        $this->assertCount(1, $reconciler->reconcile());
        $this->assertTrue($halt->isHalted());
    }

    public function test_reconciliation_halts_at_once_when_the_exchange_rejects_the_query()
    {
        config(['trading.live.enabled' => true]);

        $answer = ['orderId' => 77, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20',
            'transactTime' => now()->getTimestampMs(),
            'fills' => [['price' => '100', 'qty' => '0.2', 'commission' => '0.02', 'commissionAsset' => 'USDT']]];
        $status = 200;
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            'api.binance.com/api/v3/order*' => function () use (&$answer, &$status) {
                return Http::response($answer, $status);
            }]);

        app(OrderExecutor::class)->execute($this->signal($this->livePortfolio()), 'user:1');

        $answer = ['code' => -2013, 'msg' => 'Order does not exist.'];
        $status = 400;
        $this->assertCount(1, app(OrderReconciler::class)->reconcile());
        $this->assertTrue(app(TradingHalt::class)->isHalted());
    }

    public function test_mt5_live_orders_go_through_the_guarded_http_gateway()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00', 'America/Sao_Paulo'));
        config(['trading.live.enabled' => true, 'trading.mt5.gateway_url' => 'http://mt5.test', 'trading.b3_gateway' => Mt5HttpGateway::class]);
        Http::fake(['mt5.test/orders' => Http::response(['ticket' => 9, 'status' => 'filled', 'filled_volume' => 100, 'avg_price' => 38, 'fee' => 1])]);
        $asset = Asset::factory()->b3()->create();
        $portfolio = $this->livePortfolio($this->credential(['broker' => 'mt5', 'api_key' => '123']), ['market' => $asset->market, 'broker' => 'mt5']);
        $request = new OrderRequest($asset, OrderSide::Buy, 100);

        $result = app(BrokerManager::class)->for($portfolio)->placeOrder($request);

        $this->assertSame('9', $result->externalId);
        Http::assertSent(fn ($r) => $r['login'] === '123' && $r['volume'] == 100);

        app(TradingHalt::class)->halt('teste');
        $this->expectException(LiveTradingException::class);
        app(BrokerManager::class)->for($portfolio)->placeOrder($request);
    }
}
