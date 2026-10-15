<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Trading\Brokers\PaperBroker;
use App\Trading\Data\OrderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class PaperBrokerTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    public function test_market_buy_fills_with_slippage_fees_and_lot_rounding()
    {
        config(['trading.costs.crypto' => ['fee_rate' => 0.001, 'slippage_bps' => 10]]);
        $asset = Asset::factory()->create(['lot_size' => '0.001', 'tick_size' => '0.01']);
        $this->quote($asset, 100);

        $result = app(PaperBroker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 1.23456));

        $this->assertSame(OrderStatus::Filled, $result->status);
        $this->assertEqualsWithDelta(1.234, $result->filledQty, 1e-9);
        $this->assertEqualsWithDelta(100.10, $result->avgPrice, 1e-9);
        $this->assertEqualsWithDelta(1.234 * 100.10 * 0.001, $result->fees, 1e-6);
    }

    public function test_b3_orders_are_rejected_outside_the_session()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00', 'America/Sao_Paulo')); // Saturday
        $asset = Asset::factory()->b3()->create();
        $this->quote($asset, 38);

        $result = app(PaperBroker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 100));

        $this->assertSame(OrderStatus::Rejected, $result->status);
        $this->assertSame('Mercado fechado.', $result->rejectReason);
    }

    public function test_b3_quantity_is_rounded_down_to_the_standard_lot()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00', 'America/Sao_Paulo')); // Thursday
        $asset = Asset::factory()->b3()->create();
        $this->quote($asset, 38);

        $result = app(PaperBroker::class)->placeOrder(new OrderRequest($asset, OrderSide::Buy, 250));

        $this->assertSame(200.0, $result->filledQty);
    }
}
