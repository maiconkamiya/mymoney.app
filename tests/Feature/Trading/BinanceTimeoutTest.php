<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PortfolioMode;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\User;
use App\Trading\Data\OrderRequest;
use App\Trading\Live\UnknownSales;
use App\Trading\OrderExecutor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

/**
 * Tempo esgotado na Binance durante a saída de uma posição real (#198): a
 * conexão que não abre é refeita na hora, a venda incerta é consultada pelo
 * id antes de sair de novo e a carteira não para à espera de uma pessoa.
 */
class BinanceTimeoutTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private const CONNECT = 'cURL error 28: Connection timed out after 3001 milliseconds';

    private const READ = 'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received';

    private Asset $asset;

    /** @var list<string> method and path of each call to the exchange */
    private array $calls = [];

    /** Free BTC on the account. */
    private string $free = '0.2';

    protected function setUp(): void
    {
        parent::setUp();

        config(['trading.live.enabled' => true, 'trading.live.max_order_value' => 20, 'trading.live.max_daily_value' => 100]);
        Http::preventStrayRequests();

        $this->asset = Asset::factory()->create(['symbol' => 'BTCUSDT', 'lot_size' => '0.00001', 'min_notional' => '5']);
        $this->quote($this->asset, 90);
    }

    private function portfolio(): Portfolio
    {
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);

        return Portfolio::factory()->create([
            'user_id' => $user->id,
            'mode' => PortfolioMode::Live,
            'broker' => 'binance',
            'broker_credential_id' => $credential->id,
            'auto_execute' => true,
            'live_auto_confirmed_at' => now(),
            'initial_cash' => 100,
            'cash' => 100,
        ]);
    }

    private function position(Portfolio $portfolio): Position
    {
        return $portfolio->positions()->create(['asset_id' => $this->asset->id, 'qty' => 0.2, 'avg_price' => 100, 'stop_price' => 95, 'opened_at' => now()->subMinutes(5)]);
    }

    /**
     * @param  \Closure(Request): mixed  $order  answers for /api/v3/order (POST, GET, DELETE)
     */
    private function fake(\Closure $order): void
    {
        Http::fake(function (Request $request) use ($order) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/api/v3/time') {
                return Http::response(['serverTime' => (int) (microtime(true) * 1000)]);
            }

            if ($path === '/api/v3/account') {
                return Http::response(['balances' => [['asset' => 'BTC', 'free' => $this->free, 'locked' => '0'], ['asset' => 'USDT', 'free' => '80', 'locked' => '0']]]);
            }

            $this->calls[] = $request->method().' '.$path;

            return $order($request);
        });
    }

    private static function sold(int $orderId = 5): PromiseInterface
    {
        return Http::response(['orderId' => $orderId, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '18', 'transactTime' => now()->getTimestampMs(), 'fills' => []]);
    }

    private function buy(Portfolio $portfolio): Order
    {
        $request = new OrderRequest($this->asset, OrderSide::Buy, 0.1, stopPrice: 85, clientId: 'micro-'.$portfolio->id.'-1');
        $order = app(OrderExecutor::class)->placeDirect($portfolio, $request, 'micro', 'micro');
        $this->assertInstanceOf(Order::class, $order, is_string($order) ? $order : '');

        return $order;
    }

    private function close(Position $position): ?Order
    {
        return app(OrderExecutor::class)->closePosition($position->fresh(), 'stop', 'exits');
    }

    public function test_a_connection_that_does_not_open_is_tried_again_at_once_and_the_stop_sells()
    {
        $attempts = 0;
        $this->fake(function () use (&$attempts) {
            return ++$attempts <= 2 ? throw new ConnectionException(self::CONNECT) : self::sold();
        });
        $portfolio = $this->portfolio();
        $position = $this->position($portfolio);

        $order = $this->close($position);

        $this->assertSame(OrderStatus::Filled, $order->status);
        $this->assertSame(['POST /api/v3/order', 'POST /api/v3/order', 'POST /api/v3/order'], $this->calls);
        $this->assertNotNull($position->fresh()->closed_at);
        $this->assertFalse($portfolio->fresh()->isOrdersBlocked());
    }

    public function test_a_connection_that_never_opens_does_not_stop_the_portfolio_and_the_next_pass_sells()
    {
        $down = true;
        $this->fake(function () use (&$down) {
            return $down ? throw new ConnectionException(self::CONNECT) : self::sold();
        });
        $portfolio = $this->portfolio();
        $position = $this->position($portfolio);

        $this->assertSame(OrderStatus::Rejected, $this->close($position)?->status);
        $this->assertCount(3, $this->calls);
        $this->assertFalse($portfolio->fresh()->isOrdersBlocked());
        $this->assertNull(UnknownSales::for($position->id));

        $down = false;

        $this->assertSame(OrderStatus::Filled, $this->close($position)->status);
        $this->assertNotNull($position->fresh()->closed_at);
    }

    public function test_a_sale_whose_answer_was_lost_is_looked_up_by_its_id_before_anything_goes_out_again()
    {
        $exchange = 'down';
        $this->fake(function (Request $request) use (&$exchange) {
            if ($exchange === 'down') {
                // The sale arrives and executes; the answer and the lookups get lost.
                if ($request->method() === 'POST') {
                    $this->free = '0.00000001';
                }

                throw new ConnectionException(self::READ);
            }

            return $request->method() === 'GET' ? self::sold(9) : Http::response(['code' => -2010, 'msg' => 'Account has insufficient balance for requested action.'], 400);
        });
        $portfolio = $this->portfolio();
        $position = $this->position($portfolio);

        $this->assertSame(OrderStatus::Rejected, $this->close($position)?->status);
        // The lookup by id runs twice (one more after the lost answer); the sale is not sent again.
        $this->assertSame(['POST /api/v3/order', 'GET /api/v3/order', 'GET /api/v3/order'], $this->calls);
        $this->assertFalse($portfolio->fresh()->isOrdersBlocked());
        $this->assertSame('position-'.$position->id.'-stop', UnknownSales::for($position->id));

        $exchange = 'up';
        $this->calls = [];
        $order = $this->close($position);

        // Only dust shows on the account, but the sale found at the exchange closes the whole position.
        $this->assertSame(['GET /api/v3/order'], $this->calls);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'origClientOrderId=position-'.$position->id.'-stop'));
        $this->assertSame(OrderStatus::Filled, $order->status);
        $this->assertSame('BTCUSDT:9', $order->external_id);
        $this->assertNotNull($position->fresh()->closed_at);
        $this->assertNull(UnknownSales::for($position->id));
        $this->assertSame(1, Order::where('status', OrderStatus::Filled)->count());
    }

    public function test_a_lost_sale_the_exchange_never_saw_goes_out_once_more_under_the_same_id()
    {
        $exchange = 'down';
        $this->fake(function (Request $request) use (&$exchange) {
            if ($exchange === 'down') {
                throw new ConnectionException(self::READ);
            }

            return $request->method() === 'GET' ? Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400) : self::sold();
        });
        $portfolio = $this->portfolio();
        $position = $this->position($portfolio);

        $this->assertSame(OrderStatus::Rejected, $this->close($position)?->status);

        $exchange = 'up';
        $this->calls = [];
        $order = $this->close($position);

        $this->assertSame(['GET /api/v3/order', 'POST /api/v3/order'], $this->calls);
        $this->assertSame(OrderStatus::Filled, $order->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'newClientOrderId=position-'.$position->id.'-stop'));
        $this->assertNotNull($position->fresh()->closed_at);
    }

    public function test_a_cancel_of_the_target_sale_without_answer_is_repeated_at_once_before_the_stop_sells()
    {
        $deletes = 0;
        $this->fake(function (Request $request) use (&$deletes) {
            return match ($request->method()) {
                'DELETE' => ++$deletes === 1 ? throw new ConnectionException(self::READ) : Http::response(['code' => -2011, 'msg' => 'Unknown order sent.'], 400),
                'GET' => Http::response(['orderId' => 4, 'status' => 'CANCELED', 'executedQty' => '0', 'cummulativeQuoteQty' => '0']),
                default => self::sold(),
            };
        });
        $portfolio = $this->portfolio();
        $position = $this->position($portfolio);
        $resting = Order::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'position_id' => $position->id, 'broker' => 'binance', 'side' => 'sell', 'type' => OrderType::Limit, 'qty' => 0.2, 'price' => 101, 'status' => OrderStatus::Pending, 'external_id' => 'BTCUSDT:4', 'reason' => 'target']);

        $order = $this->close($position);

        $this->assertSame(['DELETE /api/v3/order', 'DELETE /api/v3/order', 'GET /api/v3/order', 'POST /api/v3/order'], $this->calls);
        $this->assertSame(OrderStatus::Cancelled, $resting->fresh()->status);
        $this->assertSame(OrderStatus::Filled, $order->status);
        $this->assertNotNull($position->fresh()->closed_at);
    }

    public function test_a_buy_whose_answer_was_lost_still_stops_the_portfolio()
    {
        $this->fake(fn () => throw new ConnectionException(self::READ));
        $portfolio = $this->portfolio();

        $order = $this->buy($portfolio);

        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertTrue($portfolio->fresh()->isOrdersBlocked());
    }

    public function test_a_buy_whose_connection_never_opened_does_not_stop_the_portfolio()
    {
        $this->fake(fn () => throw new ConnectionException(self::CONNECT));
        $portfolio = $this->portfolio();

        $order = $this->buy($portfolio);

        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertSame(['POST /api/v3/order', 'POST /api/v3/order', 'POST /api/v3/order'], $this->calls);
        $this->assertFalse($portfolio->fresh()->isOrdersBlocked());
    }
}
