<?php

namespace Tests\Feature\Trading;

use App\Enums\MicroRule;
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
use App\Trading\BrokerManager;
use App\Trading\Brokers\BinanceBroker;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\LiveLimits;
use App\Trading\Micro\MicroBacktester;
use App\Trading\Micro\MicroEntryPrice;
use App\Trading\Micro\MicroOverview;
use App\Trading\Micro\MicroRules;
use App\Trading\Micro\MicroSetup;
use App\Trading\Micro\MicroTrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Entrada das micro operações com compra limitada no preço sugerido (#187).
 */
class MicroPrecoSugeridoTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->asset = Asset::factory()->create(['symbol' => 'DOGEUSDT', 'tick_size' => '0.01']);
    }

    /**
     * Same chart as MicroOperationsTest: flat, one spike, then a slow climb near the support.
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
            'micro_limit_seconds' => 60,
        ]);
    }

    private function quoteAt(float $price): void
    {
        Candle::where('asset_id', $this->asset->id)->latest('open_at')->first()->update(['open' => $price, 'high' => $price, 'low' => $price, 'close' => $price]);
    }

    public function test_the_suggested_price_pulls_back_from_the_last_price_never_above_it_nor_below_the_support()
    {
        $prices = new MicroEntryPrice;
        $setup = new MicroSetup(price: 100.0, atrPct: 0.4, uptrend: true, support: 99.0, resistance: 102.0, distanceToSupportPct: 1, roomToResistancePct: 2, entry: true, reachPct: 3, reasons: []);

        // Half the average 1-minute range (0.4%) below 100, on the 0.01 tick.
        $this->assertEqualsWithDelta(99.8, $prices->suggest($setup, $this->asset), 1e-9);
        // The quote of now below the last close is the reference.
        $this->assertEqualsWithDelta(99.6, $prices->suggest($setup, $this->asset, 99.8), 1e-9);
        // Never below the support.
        $near = new MicroSetup(price: 100.0, atrPct: 4.0, uptrend: true, support: 99.5, resistance: 102.0, distanceToSupportPct: 0.5, roomToResistancePct: 2, entry: true, reachPct: 3, reasons: []);
        $this->assertEqualsWithDelta(99.5, $prices->suggest($near, $this->asset), 1e-9);
        // A support above the quote does not lift the price over the quote.
        $this->assertEqualsWithDelta(99.0, $prices->suggest($near, $this->asset, 99.0), 1e-9);
    }

    public function test_a_simulated_entry_is_a_limit_buy_at_the_suggested_price_that_starts_the_cycle_when_the_price_touches_it()
    {
        $this->candles();
        $portfolio = $this->micro();
        $trader = app(MicroTrader::class);

        $this->assertSame(1, $trader->run($portfolio));

        $cycle = MicroCycle::sole();
        $buy = Order::where('side', OrderSide::Buy)->sole();
        $this->assertSame('pending', $cycle->status);
        $this->assertSame(OrderType::Limit, $buy->type);
        $this->assertSame(OrderStatus::Pending, $buy->status);
        $this->assertLessThan(100.15, $buy->price);
        $this->assertEqualsWithDelta($cycle->suggested_price, $buy->price, 1e-9);
        $this->assertSame(0, $portfolio->openPositions()->count());
        $this->assertEqualsWithDelta(20, $buy->qty * $buy->price, 0.2);
        $this->assertNotNull($cycle->expires_at);

        // One buy per pair at a time, and the waiting buy uses the wallet budget.
        $this->assertSame(0, $trader->run($portfolio));
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.limit_placed', 'subject_id' => $cycle->id]);
        $this->assertEqualsWithDelta($buy->qty * $buy->price, $trader->walletBudget($portfolio)['used'], 0.01);

        // The price keeps above the limit: nothing executes.
        $trader->pendingBuys($portfolio);
        $this->assertSame('pending', $cycle->fresh()->status);

        // The stream price touches the limit: the buy fills at the limit, target and stop count from it.
        $this->quoteAt((float) $buy->price);
        $this->assertSame(1, $trader->pendingBuys($portfolio));

        $cycle->refresh();
        $position = $portfolio->openPositions()->sole();
        $this->assertSame('open', $cycle->status);
        $this->assertSame(OrderStatus::Filled, $buy->fresh()->status);
        $this->assertEqualsWithDelta((float) $buy->price, $cycle->entry_price, 1e-9);
        $this->assertEqualsWithDelta($cycle->entry_price * 1.005, $cycle->target_price, 1e-6);
        $this->assertEqualsWithDelta($cycle->entry_price * 0.995, $cycle->stop_price, 1e-6);
        $this->assertSame($position->id, $cycle->position_id);
        $this->assertNull($cycle->expires_at);
        $this->assertSame(1, Order::where('side', OrderSide::Sell)->where('type', OrderType::Limit)->where('status', OrderStatus::Pending)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.opened', 'subject_id' => $cycle->id]);
    }

    public function test_an_expired_limit_buy_is_cancelled_leaves_no_cycle_and_the_next_pass_tries_again()
    {
        $this->candles();
        $portfolio = $this->micro(['micro_limit_seconds' => 30]);
        $trader = app(MicroTrader::class);

        $trader->run($portfolio);
        $first = Order::where('side', OrderSide::Buy)->sole();

        $this->travel(31)->seconds();
        $this->assertSame(1, $trader->run($portfolio));

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.limit_expired', 'subject_id' => $first->id]);
        $this->assertSame(1, MicroCycle::count());
        $this->assertSame('pending', MicroCycle::sole()->status);
        $this->assertNotSame($first->id, MicroCycle::sole()->buy_order_id);

        $card = app(MicroOverview::class)->for($portfolio->fresh());
        $this->assertSame(1, $card['today']['limits_expired']);
        $this->assertSame('limit', $card['entry_mode']);
        $this->assertSame('pending', $card['cycles'][0]['status']);
        $this->assertSame('limit', $card['cycles'][0]['entry_mode']);
    }

    public function test_switching_the_mode_off_cancels_the_waiting_buy()
    {
        $this->candles();
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $portfolio->update(['micro_enabled' => false]);
        $this->artisan('micro:run')->assertSuccessful();

        $this->assertSame(0, MicroCycle::count());
        $this->assertSame(OrderStatus::Cancelled, Order::where('side', OrderSide::Buy)->sole()->status);
    }

    public function test_the_market_option_buys_at_once_and_records_the_suggested_price_next_to_the_paid_one()
    {
        $this->candles();
        $portfolio = $this->micro(['micro_entry_mode' => 'market']);

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $cycle = MicroCycle::sole();
        $this->assertSame('open', $cycle->status);
        $this->assertSame(OrderType::Market, Order::where('side', OrderSide::Buy)->sole()->type);
        $this->assertNotNull($cycle->suggested_price);
        $this->assertLessThan($cycle->entry_price, $cycle->suggested_price);
    }

    public function test_the_card_and_explain_show_the_suggested_price()
    {
        $this->candles();
        $portfolio = $this->micro();

        $focus = app(MicroOverview::class)->for($portfolio)['focus'][0];
        $this->assertTrue($focus['entry']);
        $this->assertSame('limit', $focus['suggestion']['mode']);
        $this->assertLessThan($focus['suggestion']['reference'], $focus['suggestion']['price']);
        $this->assertSame(60, $focus['suggestion']['seconds']);

        $this->artisan('micro:run --explain')
            ->expectsOutputToContain('compra limitada a '.round($focus['suggestion']['price'], 8))
            ->assertSuccessful();
    }

    public function test_only_the_owner_sets_the_entry_and_the_validity_within_the_range()
    {
        $portfolio = $this->micro();
        $other = User::factory()->create();
        $payload = ['micro_enabled' => true, 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2, 'micro_entry_mode' => 'market', 'micro_limit_seconds' => 120];

        $this->actingAs($other)->put(route('portfolios.micro', $portfolio), $payload)->assertForbidden();
        $this->assertSame('limit', $portfolio->fresh()->micro_entry_mode);

        $this->actingAs($portfolio->user)->put(route('portfolios.micro', $portfolio), ['micro_limit_seconds' => 5] + $payload)->assertSessionHasErrors('micro_limit_seconds');
        $this->actingAs($portfolio->user)->put(route('portfolios.micro', $portfolio), $payload)->assertSessionHasNoErrors();

        $portfolio->refresh();
        $this->assertSame('market', $portfolio->micro_entry_mode);
        $this->assertSame(120, $portfolio->micro_limit_seconds);
    }

    /**
     * A real portfolio on a faked Binance: the buy (order 1) answers `$buy`, the target sale is order 2.
     *
     * @param  array{status: string, executedQty: string, cummulativeQuoteQty: string}  $buy
     */
    private function liveMicro(array &$buy, array &$sent = []): Portfolio
    {
        config(['trading.live.enabled' => true]);
        $this->asset->update(['lot_size' => '0.0001', 'tick_size' => '0.01', 'min_qty' => '0.0001', 'min_notional' => '5']);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);

        Http::fake(function (Request $request) use (&$buy, &$sent) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $url = $request->url();
            // What the buys put on the account, the market completion's 0.15 included.
            $doge = $buy['status'] === 'NEW' ? '0' : (string) ((float) $buy['executedQty'] + (str_contains(implode(' ', $sent), 'type=MARKET') ? 0.15 : 0));

            if ($request->method() !== 'GET') {
                $sent[] = $request->method().' '.$url;
            }

            return match (true) {
                $path === '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
                $path === '/api/v3/myTrades' => Http::response([]),
                $path === '/api/v3/account' => Http::response(['balances' => [['asset' => 'DOGE', 'free' => $doge, 'locked' => '0'], ['asset' => 'USDT', 'free' => '30', 'locked' => '20']]]),
                $path === '/api/v3/openOrders' => Http::response([['symbol' => 'DOGEUSDT', 'orderId' => 1, 'clientOrderId' => 'micro-1-1-20261019000000']]),
                $request->method() === 'POST' && str_contains($url, 'type=LIMIT_MAKER') => Http::response(['orderId' => 1, 'status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0', 'fills' => []]),
                $request->method() === 'POST' && str_contains($url, 'type=LIMIT') => Http::response(['orderId' => 2, 'status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0', 'fills' => []]),
                $request->method() === 'POST' => Http::response(['orderId' => 3, 'status' => 'FILLED', 'executedQty' => '0.15', 'cummulativeQuoteQty' => '15.0', 'transactTime' => now()->getTimestampMs(), 'fills' => [['price' => '100.0', 'qty' => '0.15', 'commission' => '0.015', 'commissionAsset' => 'USDT']]]),
                $request->method() === 'DELETE' && str_contains($url, 'orderId=1') => (function () use (&$buy) {
                    $buy['status'] = 'CANCELED';

                    return Http::response(['orderId' => 1, 'status' => 'CANCELED']);
                })(),
                str_contains($url, 'orderId=1') => Http::response(['orderId' => 1] + $buy + ['transactTime' => now()->getTimestampMs()]),
                default => Http::response(['orderId' => 2, 'status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0']),
            };
        });

        return $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id, 'auto_execute' => true, 'live_auto_confirmed_at' => now(), 'cash' => 50, 'initial_cash' => 50]);
    }

    public function test_a_real_entry_rests_as_limit_maker_and_the_cycle_starts_at_the_price_binance_executed()
    {
        $this->candles();
        $buy = ['status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0'];
        $portfolio = $this->liveMicro($buy);
        $trader = app(MicroTrader::class);

        $this->assertSame(1, $trader->run($portfolio));

        $order = Order::where('side', OrderSide::Buy)->sole();
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'side=BUY')
            && str_contains($request->url(), 'type=LIMIT_MAKER')
            && str_contains($request->url(), 'price='.rtrim(rtrim(number_format((float) $order->price, 8, '.', ''), '0'), '.').'&')
            && ! str_contains($request->url(), 'timeInForce')
            && str_contains($request->url(), 'newClientOrderId=micro-'));
        $this->assertSame(OrderStatus::Pending, $order->status);
        // The waiting buy already counts against the real daily cap.
        $this->assertEqualsWithDelta($order->qty * $order->price, app(LiveLimits::class)->spentToday($portfolio), 0.01);

        // The account sync does not read the coins of a waiting buy as a new position.
        $buy = ['status' => 'FILLED', 'executedQty' => '0.1998', 'cummulativeQuoteQty' => '19.99998'];
        app(BinanceAccountSync::class)->sync($portfolio->fresh());
        $this->assertSame(0, $portfolio->openPositions()->count());

        $this->assertSame(1, $trader->pendingBuys($portfolio->fresh()));

        $cycle = MicroCycle::sole();
        $this->assertSame('open', $cycle->status);
        $this->assertEqualsWithDelta(100.1, $cycle->entry_price, 1e-6);
        $this->assertEqualsWithDelta(0.1998, $cycle->qty, 1e-9);
        $this->assertEqualsWithDelta(100.1 * 1.005, $cycle->target_price, 1e-6);
        $this->assertSame(OrderStatus::Filled, $order->fresh()->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'side=SELL') && str_contains($request->url(), 'type=LIMIT&'));
    }

    public function test_a_real_buy_filled_in_part_under_the_minimum_is_completed_at_market_when_it_expires()
    {
        $this->candles();
        $buy = ['status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0'];
        $sent = [];
        $portfolio = $this->liveMicro($buy, $sent);
        $trader = app(MicroTrader::class);
        $trader->run($portfolio);

        // 0.05 bought at 100.1 (5 USDT, under the stop's cover of the 5 USDT minimum) when the validity ends.
        $buy = ['status' => 'PARTIALLY_FILLED', 'executedQty' => '0.05', 'cummulativeQuoteQty' => '5.005'];
        $trader->pendingBuys($portfolio->fresh());
        $this->assertSame('pending', MicroCycle::sole()->status);

        $this->travel(61)->seconds();
        // A candle of the current minute keeps the quote fresh for the risk guard.
        Candle::query()->updateOrInsert(['asset_id' => $this->asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute()], ['open' => 100.1, 'high' => 100.1, 'low' => 100.1, 'close' => 100.1, 'volume' => 1]);
        $this->assertSame(1, $trader->pendingBuys($portfolio->fresh()));

        $this->assertTrue(collect($sent)->contains(fn (string $line) => str_starts_with($line, 'DELETE') && str_contains($line, 'orderId=1')));
        $this->assertTrue(collect($sent)->contains(fn (string $line) => str_starts_with($line, 'POST') && str_contains($line, 'side=BUY') && str_contains($line, 'type=MARKET')));
        $cycle = MicroCycle::sole();
        $this->assertSame('open', $cycle->status);
        $this->assertEqualsWithDelta(0.2, $cycle->qty, 1e-9);
        // The paid average: 5.005 at the limit plus 15 at market.
        $this->assertEqualsWithDelta(20.005 / 0.2, $cycle->entry_price, 1e-6);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.limit_completed', 'subject_id' => $cycle->id]);
        // The target sale covers the whole position.
        $this->assertTrue(collect($sent)->contains(fn (string $line) => str_contains($line, 'side=SELL') && str_contains($line, 'quantity=0.2&')));
        // The cancelled buy that executed in part still counts against the daily cap.
        $this->assertGreaterThan(19.9, app(LiveLimits::class)->spentToday($portfolio));
    }

    public function test_parar_tudo_cancels_the_micro_limit_buys_on_binance_too()
    {
        $buy = ['status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0'];
        $sent = [];
        $portfolio = $this->liveMicro($buy, $sent);

        /** @var BinanceBroker $broker */
        $broker = app(BrokerManager::class)->for($portfolio);
        $result = $broker->cancelOpenOrders();

        $this->assertSame(['DOGEUSDT:1'], $result['cancelled']);
    }

    public function test_the_backtest_buys_only_when_a_later_candle_touches_the_limit()
    {
        // A rule that always says "enter", 1% target and stop, on flat candles at 100.
        $rules = \Mockery::mock(MicroRules::class);
        $rules->allows('lookbackMinutes')->andReturn(10);
        $rules->allows('read')->andReturnUsing(fn (MicroRule $rule, array $window) => new MicroSetup(price: end($window)['close'], atrPct: 1.0, uptrend: true, support: 0.0, resistance: 200.0, distanceToSupportPct: 1, roomToResistancePct: 5, entry: true, reachPct: 5, reasons: []));
        $rules->allows('exits')->andReturn(['target_pct' => 1.0, 'stop_pct' => 1.0, 'max_hold_minutes' => 60]);

        $t = 1_700_000_000;
        $candles = [];

        for ($i = 0; $i < 14; $i++) {
            [$low, $high] = match ($i) {
                11 => [99.4, 100.1],   // touches the 99.5 limit of the decision at minute 10
                12 => [99.9, 100.6],   // reaches the target 99.5 x 1.01
                default => [99.8, 100.1],
            };
            $candles[] = ['t' => $t + $i * 60, 'open' => 100.0, 'high' => $high, 'low' => $low, 'close' => 100.0, 'volume' => 1];
        }

        $backtester = new MicroBacktester($rules, new MicroEntryPrice);
        $trades = $backtester->simulate(MicroRule::Scalp, $candles, ['net_margin_pct' => 0.2, 'fee_rate' => 0.001, 'slippage_bps' => 5, 'cooldown_minutes' => 10, 'entry' => 'limit', 'limit_minutes' => 1]);

        // The decisions of minutes 0 to 9 expired unfilled (the next candle never reached 99.5); the one at minute 10 filled at 99.5.
        $this->assertSame(10, $backtester->lastUnfilled());
        $this->assertCount(1, $trades);
        $this->assertSame('limit', $trades[0]['entry']);
        $this->assertSame('target', $trades[0]['exit']);
        $this->assertSame($t + 11 * 60, $trades[0]['opened_at']);
        $this->assertEqualsWithDelta(1.0 - 0.2, $trades[0]['net_pct'], 1e-9);

        // At market the first decision buys at 100.05 (open plus slippage), whose 101.05 target this data never reaches.
        $market = $backtester->simulate(MicroRule::Scalp, $candles, ['net_margin_pct' => 0.2, 'fee_rate' => 0.001, 'slippage_bps' => 5, 'cooldown_minutes' => 10]);
        $this->assertSame([], $market);
        $this->assertSame(0, $backtester->lastUnfilled());
    }
}
