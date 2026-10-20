<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PortfolioMode;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\BnbFees;
use App\Trading\OrderExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class TaxaEmBnbTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trading.live.enabled' => true, 'trading.live.max_order_value' => 20, 'trading.live.max_daily_value' => 30]);
        Http::preventStrayRequests();

        $this->asset = Asset::factory()->create(['symbol' => 'BTCUSDT', 'lot_size' => '0.00001']);
        $this->quote($this->asset, 100);
    }

    private function livePortfolio(array $attributes = []): Portfolio
    {
        $credential = BrokerCredential::create([
            'user_id' => User::factory()->create()->id,
            'broker' => 'binance',
            'label' => 'teste',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'can_withdraw' => false,
            'verified_at' => now(),
        ]);

        return Portfolio::factory()->create($attributes + [
            'user_id' => $credential->user_id,
            'mode' => PortfolioMode::Live,
            'broker' => 'binance',
            'broker_credential_id' => $credential->id,
            'initial_cash' => 1_000,
            'cash' => 1_000,
        ]);
    }

    private function buy(Portfolio $portfolio): Order
    {
        $signal = Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $this->asset->id,
            'stage' => SignalStage::Confirmed,
            'side' => 'buy',
            'stop_price' => 95,
            'decided_by' => 'user:1',
        ]);

        return app(OrderExecutor::class)->execute($signal, 'user:1');
    }

    private function fakeFill(string $commission, string $asset, ?string $bnbPrice): void
    {
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            '*/api/v3/ticker/price*' => $bnbPrice === null ? Http::response(['code' => -1], 500) : Http::response(['symbol' => 'BNBUSDT', 'price' => $bnbPrice]),
            'api.binance.com/api/v3/order*' => Http::response([
                'orderId' => 77, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20',
                'transactTime' => now()->getTimestampMs(),
                'fills' => [['price' => '100', 'qty' => '0.2', 'commission' => $commission, 'commissionAsset' => $asset]],
            ])]);
    }

    public function test_a_fee_paid_in_bnb_is_booked_in_usdt_at_the_bnb_price_and_keeps_its_asset()
    {
        // 20 USDT × 0,075% = 0,015 USDT = 0,000025 BNB a 600.
        $this->fakeFill('0.000025', 'BNB', '600');
        $portfolio = $this->livePortfolio();

        $order = $this->buy($portfolio);

        $this->assertSame(OrderStatus::Filled, $order->status, (string) $order->reject_reason);
        $this->assertSame('BNB', $order->fresh()->fee_asset);
        $this->assertEqualsWithDelta(0.015, $order->fresh()->fees, 1e-9);
        $this->assertEqualsWithDelta(980 - 0.015, $portfolio->fresh()->cash, 1e-6);
        // Paid in BNB, the whole quantity bought stays to be sold.
        $this->assertEqualsWithDelta(0.2, $portfolio->openPositions()->sole()->qty, 1e-9);
    }

    public function test_a_bnb_fee_without_the_bnb_price_gets_the_discounted_rate_never_zero()
    {
        $this->fakeFill('0.000025', 'BNB', null);
        $portfolio = $this->livePortfolio();

        $order = $this->buy($portfolio);

        $this->assertEqualsWithDelta(20 * 0.001 * 0.75, $order->fresh()->fees, 1e-9);
        $this->assertSame('BNB', $order->fresh()->fee_asset);
    }

    public function test_a_fee_in_usdt_records_usdt()
    {
        $this->fakeFill('0.02', 'USDT', '600');

        $order = $this->buy($this->livePortfolio());

        $this->assertSame('USDT', $order->fresh()->fee_asset);
        $this->assertEqualsWithDelta(0.02, $order->fresh()->fees, 1e-9);
    }

    public function test_the_sync_reads_whether_the_account_pays_fees_in_bnb()
    {
        $portfolio = $this->livePortfolio(['micro_order_value' => 5.5]);
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            '*/api/v3/account/commission*' => Http::response([
                'symbol' => 'BTCUSDT',
                'standardCommission' => ['maker' => '0.001', 'taker' => '0.001'],
                'discount' => ['enabledForAccount' => true, 'enabledForSymbol' => true, 'discountAsset' => 'BNB', 'discount' => '0.75000000'],
            ]),
            '*/api/v3/account*' => Http::response(['balances' => [
                ['asset' => 'USDT', 'free' => '30', 'locked' => '0'],
                ['asset' => 'BNB', 'free' => '0.004', 'locked' => '0'],
            ]]),
            '*/api/v3/ticker/price*' => Http::response([['symbol' => 'BNBUSDT', 'price' => '600']]),
            '*/api/v3/allOrders*' => Http::response([]),
            '*/sapi/*' => Http::response([], 404),
        ]);

        app(BinanceAccountSync::class)->sync($portfolio);

        $this->assertTrue($portfolio->fresh()->sync_report['bnb_discount']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v3/account/commission') && str_contains($request->url(), 'symbol=BTCUSDT'));

        // 0,004 BNB a 600 = 2,40 USDT; um ciclo de 5,5 USDT paga 2 × 5,5 × 0,075% = 0,00825.
        $status = app(BnbFees::class)->for($portfolio->fresh());
        $this->assertSame('ativo', $status['state']);
        $this->assertSame(290, $status['cycles']);
    }

    public function test_the_sync_still_runs_when_the_commission_endpoint_fails()
    {
        $portfolio = $this->livePortfolio();
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            '*/api/v3/account/commission*' => Http::response(['code' => -1, 'msg' => 'erro'], 500),
            '*/api/v3/account*' => Http::response(['balances' => [['asset' => 'USDT', 'free' => '30', 'locked' => '0']]]),
            '*/api/v3/ticker/price*' => Http::response([]),
            '*/api/v3/allOrders*' => Http::response([]),
            '*/sapi/*' => Http::response([], 404),
        ]);

        app(BinanceAccountSync::class)->sync($portfolio);

        $this->assertEqualsWithDelta(30, $portfolio->fresh()->cash, 1e-6);
        $this->assertNull($portfolio->fresh()->sync_report['bnb_discount']);
        $this->assertSame('desconhecido', app(BnbFees::class)->for($portfolio->fresh())['state']);
    }

    private function report(Portfolio $portfolio, ?bool $enabled, float $bnbValue): void
    {
        $portfolio->update(['sync_report' => [
            'holdings' => $bnbValue > 0 ? [['coin' => 'BNB', 'qty' => $bnbValue / 600, 'price' => 600.0, 'value' => $bnbValue, 'status' => 'posicao', 'reason' => null]] : [],
            'bnb_discount' => $enabled,
        ]]);
    }

    private function filledOrder(Portfolio $portfolio, string $feeAsset, string $when): void
    {
        Order::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $this->asset->id,
            'broker' => 'binance',
            'external_id' => 'BTCUSDT:'.random_int(1, 999_999),
            'side' => OrderSide::Buy,
            'type' => OrderType::Market,
            'qty' => 0.05,
            'price' => 100,
            'status' => OrderStatus::Filled,
            'fees' => 0.005,
            'fee_asset' => $feeAsset,
            'filled_at' => now()->parse($when),
        ]);
    }

    public function test_the_card_warns_when_the_bnb_is_running_out_or_binance_charged_in_the_coin()
    {
        $portfolio = $this->livePortfolio(['micro_order_value' => 5.5]);
        $bnb = app(BnbFees::class);

        $this->report($portfolio, true, 0.1);
        $this->assertSame('acabando', $bnb->for($portfolio->fresh())['state']);
        $this->assertStringContainsString('cerca de 12 ciclos', $bnb->for($portfolio->fresh())['message']);

        $this->report($portfolio, true, 0.0);
        $this->assertSame('sem_bnb', $bnb->for($portfolio->fresh())['state']);

        $this->report($portfolio, true, 3.0);
        $this->filledOrder($portfolio, 'BNB', '-2 hours');
        $this->assertSame('ativo', $bnb->for($portfolio->fresh())['state']);

        $this->filledOrder($portfolio, 'BTC', '-1 hour');
        $status = $bnb->for($portfolio->fresh());
        $this->assertSame('sem_bnb', $status['state']);
        $this->assertStringContainsString('pagou a taxa em BTC', $status['message']);

        $this->report($portfolio, false, 3.0);
        $this->assertSame('desligado', $bnb->for($portfolio->fresh())['state']);

        // Sem a leitura da conta, a ordem executada em BNB basta para dizer que o desconto vale.
        $this->report($portfolio, null, 3.0);
        $this->filledOrder($portfolio, 'BNB', 'now');
        $this->assertSame('ativo', $bnb->for($portfolio->fresh())['state']);
    }

    public function test_the_portfolio_page_shows_the_bnb_status_only_on_a_real_portfolio_to_its_owner()
    {
        $live = $this->livePortfolio();
        $this->report($live, true, 3.0);
        $paper = Portfolio::factory()->create();

        $this->actingAs($live->user)->get(route('portfolios.show', $live))->assertInertia(fn (Assert $page) => $page
            ->where('micro.bnb_fees.state', 'ativo'));
        $this->actingAs($paper->user)->get(route('portfolios.show', $paper))->assertInertia(fn (Assert $page) => $page
            ->where('micro.bnb_fees', null));
        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $live))->assertForbidden();
    }
}
