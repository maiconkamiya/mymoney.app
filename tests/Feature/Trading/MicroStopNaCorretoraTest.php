<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\BrokerCredential;
use App\Models\Candle;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Live\LiveLimits;
use App\Trading\Live\OrderReconciler;
use App\Trading\Micro\MicroOverview;
use App\Trading\Micro\MicroTrader;
use App\Trading\PositionExits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Stop na corretora" (#210): the micro cycle leaves its target and its stop
 * at the exchange as one OCO, instead of the app selling at market when the
 * price crosses the stop.
 */
class MicroStopNaCorretoraTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    /** How many OCOs the fake Binance received. */
    private int $ocoPosts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->asset = Asset::factory()->create(['symbol' => 'DOGEUSDT']);
        $this->candles();
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

    private function advanceTo(float $price): void
    {
        Candle::where('asset_id', $this->asset->id)->latest('open_at')->first()->update(['open' => $price, 'high' => $price, 'low' => $price, 'close' => $price]);
    }

    private function micro(array $attributes = []): Portfolio
    {
        return Portfolio::factory()->create($attributes + [
            'micro_enabled' => true,
            'micro_entry_mode' => 'market',
            'micro_order_value' => 20,
            'micro_net_margin_pct' => 0.2,
            'micro_exchange_stop' => true,
        ]);
    }

    /**
     * @return array{0: Order, 1: Order} the target leg and the stop leg
     */
    private function legs(): array
    {
        $target = Order::where('side', OrderSide::Sell)->where('reason', 'target')->sole();
        $stop = Order::where('side', OrderSide::Sell)->where('reason', 'stop')->where('type', OrderType::Limit)->sole();

        return [$target, $stop];
    }

    // --- Carteira simulada -------------------------------------------------

    public function test_the_cycle_leaves_target_and_stop_resting_as_one_oco()
    {
        $portfolio = $this->micro();
        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $cycle = MicroCycle::sole();
        [$target, $stop] = $this->legs();

        $this->assertSame(OrderStatus::Pending, $target->status);
        $this->assertSame(OrderStatus::Pending, $stop->status);
        $this->assertNotNull($target->oco_group);
        $this->assertSame($target->oco_group, $stop->oco_group);
        $this->assertTrue($stop->isOcoStop());
        $this->assertFalse($target->isOcoStop());
        $this->assertEqualsWithDelta($target->qty, $stop->qty, 1e-12);
        // Target up to the tick, stop on the tick, its limit 0.3% under it and down to the tick.
        $this->assertGreaterThanOrEqual($cycle->target_price, $target->price);
        $this->assertEqualsWithDelta(round($cycle->stop_price, 2), $stop->stop_price, 1e-8);
        $this->assertEqualsWithDelta(floor(round($stop->stop_price * 0.997 / 0.01, 8)) * 0.01, $stop->price, 1e-8);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.oco_placed', 'subject_id' => $cycle->id]);

        $row = app(MicroOverview::class)->for($portfolio)['cycles'][0];
        $this->assertSame($target->price, $row['resting_sell_price']);
        $this->assertSame(['stop' => $stop->stop_price, 'limit' => $stop->price], $row['resting_stop']);

        // Already resting: the next run registers nothing more.
        app(MicroTrader::class)->run($portfolio);
        $this->assertSame(3, Order::count());
    }

    public function test_the_stop_leg_sells_at_the_price_of_the_trigger_and_the_target_leg_goes()
    {
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        [$target, $stop] = $this->legs();

        // Between the stop and its limit: the exchange sells, the app sends nothing at market.
        $price = round(($stop->stop_price + $stop->price) / 2, 2);
        $this->advanceTo($price);
        $this->assertSame([MicroCycle::sole()->position_id], app(MicroTrader::class)->due($portfolio));
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $this->assertSame(OrderStatus::Filled, $stop->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $target->fresh()->status);
        $this->assertSame(0, Order::where('type', OrderType::Market)->where('side', OrderSide::Sell)->count());

        $cycle = MicroCycle::sole();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('stop', $cycle->exit_reason);
        $this->assertEqualsWithDelta($price, $cycle->exit_price, 1e-8);
        $this->assertLessThan(0, $cycle->net_pnl);
    }

    public function test_the_target_leg_fills_at_its_price_and_the_stop_leg_goes()
    {
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        [$target, $stop] = $this->legs();

        $this->advanceTo($target->price + 0.05);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $this->assertSame(OrderStatus::Filled, $target->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $stop->fresh()->status);
        $cycle = MicroCycle::sole();
        $this->assertSame('target', $cycle->exit_reason);
        $this->assertEqualsWithDelta($target->price, $cycle->exit_price, 1e-8);
    }

    public function test_a_price_that_falls_past_the_stop_limit_is_sold_at_market_by_the_app()
    {
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        [$target, $stop] = $this->legs();

        $this->advanceTo($stop->price - 0.5);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $this->assertSame(OrderStatus::Cancelled, $target->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $stop->fresh()->status);
        $this->assertSame('stop', Order::where('type', OrderType::Market)->where('side', OrderSide::Sell)->sole()->reason);
        $this->assertSame('stop', MicroCycle::sole()->exit_reason);
    }

    public function test_the_time_limit_cancels_both_legs_before_selling_at_market()
    {
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        [$target, $stop] = $this->legs();
        MicroCycle::query()->update(['opened_at' => now()->subHours(2)]);

        app(MicroTrader::class)->run($portfolio);

        $this->assertSame(OrderStatus::Cancelled, $target->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $stop->fresh()->status);
        $this->assertSame('micro_time', MicroCycle::oldest('id')->first()->exit_reason);
    }

    public function test_with_the_option_off_the_cycle_keeps_the_limit_sale_and_the_app_stop()
    {
        $portfolio = $this->micro(['micro_exchange_stop' => false]);
        app(MicroTrader::class)->run($portfolio);

        $sell = Order::where('side', OrderSide::Sell)->sole();
        $this->assertNull($sell->oco_group);
        $this->assertNull($sell->stop_price);
        $this->assertNull(app(MicroOverview::class)->for($portfolio)['cycles'][0]['resting_stop']);
    }

    // --- Carteira real (Binance falsa) -------------------------------------

    /**
     * A real portfolio and a fake Binance. `$oco` is how the OCO answers
     * ("ok", "refused", "timeout" with the lookup answering, "lost" without); `$legs` the state of each leg when read.
     *
     * @param  array{target: string, stop: string}  $legs
     */
    private function liveMicro(string $oco, array &$legs): Portfolio
    {
        config(['trading.live.enabled' => true]);
        $this->asset->update(['lot_size' => '0.0001', 'tick_size' => '0.01', 'min_qty' => '0.0001', 'min_notional' => '5']);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);
        $ids = ['target' => 'x', 'stop' => 'y'];

        Http::fake(function (Request $request) use ($oco, &$legs, &$ids) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $leg = function (string $which, int $id) use (&$legs, &$ids): array {
                return [
                    'symbol' => 'DOGEUSDT', 'orderId' => $id, 'clientOrderId' => $ids[$which], 'status' => $legs[$which],
                    'executedQty' => $legs[$which] === 'FILLED' ? '0.1997' : '0',
                    'cummulativeQuoteQty' => $legs[$which] === 'FILLED' ? '19.9' : '0',
                    'transactTime' => now()->getTimestampMs(),
                ];
            };

            if ($path === '/api/v3/orderList/oco') {
                $this->ocoPosts++;
                $ids = ['target' => $query['aboveClientOrderId'], 'stop' => $query['belowClientOrderId']];

                return match ($oco) {
                    'refused' => Http::response(['code' => -2010, 'msg' => 'Order would immediately trigger.'], 400),
                    'timeout', 'lost' => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'),
                    default => Http::response(['orderListId' => 7, 'listClientOrderId' => $query['listClientOrderId'], 'orderReports' => [$leg('stop', 11), $leg('target', 10)]]),
                };
            }

            return match (true) {
                $path === '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
                $path === '/api/v3/myTrades' => Http::response([]),
                $path === '/api/v3/account' => Http::response(['balances' => [['asset' => 'DOGE', 'free' => '0.19970001', 'locked' => '0'], ['asset' => 'USDT', 'free' => '30', 'locked' => '0']]]),
                $path === '/api/v3/orderList' && $oco === 'lost' => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'),
                $path === '/api/v3/orderList' => Http::response(['orderListId' => 7, 'orders' => [['symbol' => 'DOGEUSDT', 'orderId' => 10], ['symbol' => 'DOGEUSDT', 'orderId' => 11]]]),
                $request->method() === 'DELETE' => (function () use (&$legs, $query) {
                    // Cancelling one leg cancels the list; the other is then unknown to cancel.
                    if ($legs['target'] !== 'NEW') {
                        return Http::response(['code' => -2011, 'msg' => 'Unknown order sent.'], 400);
                    }

                    $legs = ['target' => 'CANCELED', 'stop' => 'CANCELED'];

                    return Http::response(['orderId' => $query['orderId'], 'status' => 'CANCELED']);
                })(),
                $request->method() === 'POST' && ($query['type'] ?? '') === 'LIMIT' => Http::response(['orderId' => 2, 'status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0']),
                $request->method() === 'POST' => Http::response(['orderId' => ($query['side'] ?? '') === 'BUY' ? 1 : 3, 'status' => 'FILLED', 'executedQty' => '0.1999', 'cummulativeQuoteQty' => '20.02', 'transactTime' => now()->getTimestampMs(), 'fills' => [['price' => '100.15', 'qty' => '0.1999', 'commission' => '0.02', 'commissionAsset' => 'USDT']]]),
                ($query['orderId'] ?? null) === '10' => Http::response($leg('target', 10)),
                ($query['orderId'] ?? null) === '11' => Http::response($leg('stop', 11)),
                default => Http::response(['orderId' => 1, 'status' => 'FILLED', 'executedQty' => '0.1999', 'cummulativeQuoteQty' => '20.02']),
            };
        });

        return $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id, 'auto_execute' => true, 'live_auto_confirmed_at' => now(), 'cash' => 50, 'initial_cash' => 50]);
    }

    public function test_a_real_cycle_sends_the_oco_and_books_the_stop_leg_the_exchange_executed()
    {
        $legs = ['target' => 'NEW', 'stop' => 'NEW'];
        $portfolio = $this->liveMicro('ok', $legs);

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        [$target, $stop] = $this->legs();
        Http::assertSent(function (Request $request) use ($target, $stop) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return parse_url($request->url(), PHP_URL_PATH) === '/api/v3/orderList/oco'
                && $q['side'] === 'SELL' && $q['quantity'] === '0.1997'
                && $q['aboveType'] === 'LIMIT_MAKER' && (float) $q['abovePrice'] === $target->price
                && $q['belowType'] === 'STOP_LOSS_LIMIT' && (float) $q['belowStopPrice'] === $stop->stop_price
                && (float) $q['belowPrice'] === $stop->price && $q['belowTimeInForce'] === 'GTC';
        });
        $this->assertSame('DOGEUSDT:10', $target->external_id);
        $this->assertSame('DOGEUSDT:11', $stop->external_id);
        $this->assertSame('DOGEUSDT:7', $stop->oco_group);

        // The two legs sell the same coins: the daily cap counts the sale once.
        $buy = Order::where('side', OrderSide::Buy)->sole();
        $this->assertEqualsWithDelta($buy->qty * $buy->price + $target->qty * $target->price, app(LiveLimits::class)->spentToday($portfolio), 1e-6);
        $this->assertSame([], app(OrderReconciler::class)->reconcile());

        // Between the stop and its limit the app waits for the exchange: no market sale, no cancel.
        $this->advanceTo(round(($stop->stop_price + $stop->price) / 2, 2));
        app(PositionExits::class)->checkStopsAndTargets();
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
        $this->assertSame(OrderStatus::Pending, $stop->fresh()->status);

        // Binance executed the stop leg and expired the target leg.
        $legs = ['target' => 'EXPIRED', 'stop' => 'FILLED'];
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $this->assertSame(OrderStatus::Filled, $stop->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $target->fresh()->status);
        $this->assertSame(0, Order::where('type', OrderType::Market)->where('side', OrderSide::Sell)->count());
        $cycle = MicroCycle::sole();
        $this->assertSame('stop', $cycle->exit_reason);
        $this->assertEqualsWithDelta(19.9 / 0.1997, $cycle->exit_price, 1e-6);
        $this->assertSame([], app(OrderReconciler::class)->reconcile());
    }

    public function test_a_real_stop_past_the_limit_cancels_the_oco_and_sells_at_market()
    {
        $legs = ['target' => 'NEW', 'stop' => 'NEW'];
        $portfolio = $this->liveMicro('ok', $legs);
        app(MicroTrader::class)->run($portfolio);
        [$target, $stop] = $this->legs();

        $this->advanceTo($stop->price - 0.5);
        app(PositionExits::class)->checkStopsAndTargets();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'orderId=10'));
        $this->assertSame(OrderStatus::Cancelled, $target->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $stop->fresh()->status);
        $this->assertSame('stop', Order::where('type', OrderType::Market)->where('side', OrderSide::Sell)->sole()->reason);
    }

    public function test_an_oco_the_exchange_refuses_falls_back_to_the_limit_sale_and_is_logged()
    {
        $legs = ['target' => 'NEW', 'stop' => 'NEW'];
        $portfolio = $this->liveMicro('refused', $legs);
        app(MicroTrader::class)->run($portfolio);

        $cycle = MicroCycle::sole();
        $this->assertSame(2, Order::where('side', OrderSide::Sell)->where('status', OrderStatus::Rejected)->count());
        $fallback = Order::where('side', OrderSide::Sell)->where('status', OrderStatus::Pending)->sole();
        $this->assertNull($fallback->oco_group);
        $this->assertSame('DOGEUSDT:2', $fallback->external_id);
        $failed = AuditLog::where('action', 'micro.oco_failed')->sole();
        $this->assertSame($cycle->id, $failed->subject_id);
        $this->assertStringContainsString('corretora recusou a OCO', $failed->payload['reason']);
        $this->assertNull($portfolio->fresh()->orders_blocked_at);
        // The plain sale has no stop leg: the app watches the stop as before.
        $this->assertNull(app(MicroOverview::class)->for($portfolio)['cycles'][0]['resting_stop']);
    }

    public function test_an_oco_whose_answer_was_lost_is_looked_up_by_its_id_before_sending_again()
    {
        $legs = ['target' => 'NEW', 'stop' => 'NEW'];
        $portfolio = $this->liveMicro('timeout', $legs);
        app(MicroTrader::class)->run($portfolio);

        // Found under its own id: booked, sent once, and the portfolio keeps trading.
        [$target, $stop] = $this->legs();
        $this->assertSame(OrderStatus::Pending, $target->status);
        $this->assertSame('DOGEUSDT:11', $stop->external_id);
        $this->assertSame('DOGEUSDT:7', $stop->oco_group);
        $this->assertSame(1, $this->ocoPosts);
        $this->assertNull($portfolio->fresh()->orders_blocked_at);
    }

    public function test_an_oco_of_unknown_fate_stops_the_real_portfolio()
    {
        $legs = ['target' => 'NEW', 'stop' => 'NEW'];
        // The lookup gets no answer either.
        $portfolio = $this->liveMicro('lost', $legs);
        app(MicroTrader::class)->run($portfolio);

        $this->assertNotNull($portfolio->fresh()->orders_blocked_at);
        $this->assertStringContainsString('Envio incerto', $portfolio->fresh()->orders_blocked_reason);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.oco_failed']);
    }

    // --- Card ---------------------------------------------------------------

    public function test_owner_turns_the_exchange_stop_on_and_the_card_shows_it_and_other_users_get_403()
    {
        $portfolio = $this->micro(['micro_enabled' => false, 'micro_exchange_stop' => false]);
        $owner = $portfolio->user;
        $settings = ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2', 'micro_exchange_stop' => '1'];

        $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $portfolio), $settings)->assertForbidden();
        $this->assertFalse($portfolio->fresh()->micro_exchange_stop);

        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), $settings)->assertRedirect();
        $this->assertTrue($portfolio->fresh()->micro_exchange_stop);

        $this->actingAs($owner)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.exchange_stop', true));

        // Saved without the field (an older screen): the choice stays.
        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2'])->assertRedirect();
        $this->assertTrue($portfolio->fresh()->micro_exchange_stop);

        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), ['micro_exchange_stop' => '0'] + $settings)->assertRedirect();
        $this->assertFalse($portfolio->fresh()->micro_exchange_stop);
    }
}
