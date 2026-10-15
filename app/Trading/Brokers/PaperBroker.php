<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Trading\Contracts\Broker;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\MarketHours;
use Illuminate\Support\Str;

/**
 * Simulated execution: fills market orders at the last price moved against us
 * by the configured slippage, rounds to lot and tick size, and charges fees.
 */
class PaperBroker implements Broker
{
    public function __construct(
        private readonly PriceFeed $prices,
        private readonly MarketHours $hours,
    ) {}

    public function placeOrder(OrderRequest $order): OrderResult
    {
        $asset = $order->asset;

        if (! $this->hours->isOpen($asset->market)) {
            return OrderResult::rejected('Mercado fechado.');
        }

        $quote = $this->prices->quote($asset);

        if ($quote === null) {
            return OrderResult::rejected('Sem cotação para '.$asset->symbol.'.');
        }

        $qty = $this->roundToLot($order->qty, (float) $asset->lot_size);

        if ($qty <= 0) {
            return OrderResult::rejected('Quantidade abaixo do lote mínimo.');
        }

        $costs = config('trading.costs.'.$asset->market->value);
        $slippage = $costs['slippage_bps'] / 10_000;
        $direction = $order->side === OrderSide::Buy ? 1 : -1;
        $price = $this->roundToTick($quote->price * (1 + $direction * $slippage), (float) $asset->tick_size);

        if ($order->type === OrderType::Limit && $order->limitPrice !== null) {
            $crosses = $order->side === OrderSide::Buy ? $price <= $order->limitPrice : $price >= $order->limitPrice;

            if (! $crosses) {
                return OrderResult::rejected('Ordem limitada não cruzaria o preço atual.');
            }
        }

        $fees = $qty * $price * $costs['fee_rate'] + ($costs['brokerage_per_order'] ?? 0);

        return new OrderResult(
            status: OrderStatus::Filled,
            externalId: 'paper-'.Str::ulid(),
            filledQty: $qty,
            avgPrice: $price,
            fees: round($fees, 8),
            filledAt: now(),
        );
    }

    public function cancelOrder(string $externalId): void
    {
        // Paper orders fill immediately; nothing stays open to cancel.
    }

    public function getBalances(): array
    {
        // Paper balances live on the portfolio row.
        return [];
    }

    public function supports(Market $market): bool
    {
        return true;
    }

    private function roundToLot(float $qty, float $lot): float
    {
        if ($lot <= 0) {
            return $qty;
        }

        return floor(round($qty / $lot, 8)) * $lot;
    }

    private function roundToTick(float $price, float $tick): float
    {
        if ($tick <= 0) {
            return $price;
        }

        return round(round($price / $tick) * $tick, 8);
    }
}
