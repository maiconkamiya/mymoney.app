<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Trading\Brokers\Mt5Broker;
use App\Trading\Brokers\PaperBroker;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Gateways\OrderGateway;
use App\Trading\Gateways\RefusingOrderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class B3BrokerTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    public function test_paper_broker_rejects_orders_during_the_auction_and_on_holidays()
    {
        $asset = Asset::factory()->b3()->create();

        Carbon::setTestNow(Carbon::parse('2026-10-15 09:50', 'America/Sao_Paulo'));
        $this->quote($asset, 38);
        $auction = app(PaperBroker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 100));
        $this->assertSame('Mercado em leilão.', $auction->rejectReason);

        Carbon::setTestNow(Carbon::parse('2026-10-12 11:00', 'America/Sao_Paulo'));
        $holiday = app(PaperBroker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 100));
        $this->assertSame('Mercado fechado.', $holiday->rejectReason);
    }

    public function test_paper_broker_rejects_less_than_a_standard_lot_but_fills_the_fractional_market()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00', 'America/Sao_Paulo'));
        $standard = Asset::factory()->b3('PETR4')->create();
        $fraction = Asset::factory()->b3('PETR4F')->create(['lot_size' => 1]);
        $this->quote($standard, 38);
        $this->quote($fraction, 38);

        $small = app(PaperBroker::class)->placeOrder(new OrderRequest($standard, OrderSide::Buy, 50));
        $this->assertSame(OrderStatus::Rejected, $small->status);

        $odd = app(PaperBroker::class)->placeOrder(new OrderRequest($fraction, OrderSide::Buy, 50));
        $this->assertSame(OrderStatus::Filled, $odd->status);
        $this->assertSame(50.0, $odd->filledQty);
    }

    public function test_b3_default_fee_covers_exchange_and_settlement()
    {
        $this->assertEqualsWithDelta(0.000325, config('trading.costs.b3.fee_rate'), 1e-12);
    }

    public function test_live_broker_sends_a_normalized_order_to_the_gateway_inside_the_session()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00', 'America/Sao_Paulo'));
        $asset = Asset::factory()->b3()->create();
        $gateway = new class implements OrderGateway
        {
            public ?OrderRequest $sent = null;

            public function send(OrderRequest $order): OrderResult
            {
                $this->sent = $order;

                return new OrderResult(OrderStatus::Pending, externalId: 'x1');
            }

            public function cancel(string $externalId): void {}

            public function balances(): array
            {
                return ['BRL' => 10.0];
            }
        };
        $this->app->instance(OrderGateway::class, $gateway);

        $result = app(Mt5Broker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 250));

        $this->assertSame('x1', $result->externalId);
        $this->assertSame(200.0, $gateway->sent->qty);
        $this->assertSame(['BRL' => 10.0], app(Mt5Broker::class)->getBalances());
    }

    public function test_live_broker_never_reaches_the_gateway_outside_continuous_trading()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 16:57', 'America/Sao_Paulo'));
        $asset = Asset::factory()->b3()->create();

        $result = app(Mt5Broker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 100));

        $this->assertSame(OrderStatus::Rejected, $result->status);
        $this->assertSame('Mercado em leilão.', $result->rejectReason);
    }

    public function test_default_gateway_refuses_to_send_real_orders()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00', 'America/Sao_Paulo'));
        $asset = Asset::factory()->b3()->create();

        $this->assertInstanceOf(RefusingOrderGateway::class, app(OrderGateway::class));
        $this->expectException(LogicException::class);

        app(Mt5Broker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 100));
    }
}
