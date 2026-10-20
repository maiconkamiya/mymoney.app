<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Trading\B3\B3Rules;
use App\Trading\Contracts\Broker;
use App\Trading\Contracts\PlacesOco;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\OcoResult;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\MarketHours;
use Illuminate\Support\Str;

/**
 * Simulated execution: fills market orders at the last price moved against us
 * by the configured slippage, rounds to lot and tick size, and charges fees.
 */
class PaperBroker implements Broker, PlacesOco
{
    public function __construct(
        private readonly PriceFeed $prices,
        private readonly MarketHours $hours,
        private readonly B3Rules $b3 = new B3Rules,
    ) {}

    public function placeOrder(OrderRequest $order): OrderResult
    {
        $asset = $order->asset;

        if (! $this->hours->isOpen($asset->market)) {
            return OrderResult::rejected(
                $this->hours->phase($asset->market)->isAuction() ? 'Mercado em leilão.' : 'Mercado fechado.',
            );
        }

        $quote = $this->prices->quote($asset);

        if ($quote === null) {
            return OrderResult::rejected('Sem cotação para '.$asset->symbol.'.');
        }

        $qty = $asset->market === Market::B3
            ? $this->b3->normalizeQuantity($asset, $order->qty)
            : $this->roundToLot($order->qty, (float) $asset->lot_size);

        if ($qty <= 0) {
            return OrderResult::rejected('Quantidade abaixo do lote mínimo.');
        }

        $costs = config('trading.costs.'.$asset->market->value);
        $slippage = $costs['slippage_bps'] / 10_000;
        $direction = $order->side === OrderSide::Buy ? 1 : -1;
        $price = $this->roundToTick($quote->price * (1 + $direction * $slippage), (float) $asset->tick_size);

        if ($order->type === OrderType::Limit && $order->limitPrice !== null) {
            $crosses = $order->side === OrderSide::Buy ? $price <= $order->limitPrice : $price >= $order->limitPrice;

            // It rests: the executor fills it at its own price once the quote reaches it.
            if (! $crosses) {
                return new OrderResult(OrderStatus::Pending, externalId: 'paper-'.Str::ulid());
            }

            // As Binance's LIMIT_MAKER: a limit that would execute on arrival is refused, not filled.
            if ($order->postOnly) {
                return OrderResult::rejected('A ordem limitada executaria na hora (preço já no limite).');
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

    /**
     * As Binance: both legs rest, refused when the price is already at the
     * target (the limit maker would execute) or under the stop. The executor
     * fills a leg once the quote reaches it and cancels the other.
     */
    public function placeOco(OrderRequest $target, OrderRequest $stop, string $clientId): OcoResult
    {
        if (! $this->hours->isOpen($target->asset->market)) {
            return OcoResult::rejected('Mercado fechado.');
        }

        $quote = $this->prices->quote($target->asset);

        if ($quote === null) {
            return OcoResult::rejected('Sem cotação para '.$target->asset->symbol.'.');
        }

        if ($quote->price >= (float) $target->limitPrice || $quote->price <= (float) $stop->stopPrice) {
            return OcoResult::rejected('Preço fora da faixa da OCO (o alvo precisa ficar acima e o stop abaixo do preço atual).');
        }

        if ($this->roundToLot($target->qty, (float) $target->asset->lot_size) <= 0) {
            return OcoResult::rejected('Quantidade abaixo do lote mínimo.');
        }

        return new OcoResult(
            new OrderResult(OrderStatus::Pending, externalId: 'paper-'.Str::ulid()),
            new OrderResult(OrderStatus::Pending, externalId: 'paper-'.Str::ulid()),
            'paper-oco-'.Str::ulid(),
        );
    }

    public function cancelOrder(string $externalId): void
    {
        // Paper orders fill immediately; nothing stays open to cancel.
    }

    public function getOrder(string $externalId): OrderResult
    {
        return OrderResult::rejected('Ordens simuladas não têm estado externo.');
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
