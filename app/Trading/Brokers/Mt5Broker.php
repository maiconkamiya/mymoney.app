<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Trading\B3\B3Rules;
use App\Trading\Contracts\Broker;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Gateways\OrderGateway;
use App\Trading\MarketHours;

/**
 * Live B3 execution. Session, lot and tick are checked here so the gateway
 * only ever receives an order the exchange would accept. The default gateway
 * refuses everything; real routing arrives with phase 5 of the spec.
 */
class Mt5Broker implements Broker
{
    public function __construct(
        private readonly OrderGateway $gateway,
        private readonly MarketHours $hours,
        private readonly B3Rules $rules = new B3Rules,
    ) {}

    public function placeOrder(OrderRequest $order): OrderResult
    {
        $asset = $order->asset;

        if (! $this->hours->isOpen($asset->market)) {
            return OrderResult::rejected(
                $this->hours->phase($asset->market)->isAuction() ? 'Mercado em leilão.' : 'Mercado fechado.',
            );
        }

        $qty = $this->rules->normalizeQuantity($asset, $order->qty);

        if ($qty <= 0) {
            return OrderResult::rejected('Quantidade abaixo do lote mínimo.');
        }

        $limit = $order->limitPrice === null ? null : $this->rules->normalizePrice($asset, $order->limitPrice);

        return $this->gateway->send(new OrderRequest(
            $asset, $order->side, $qty, $order->type, $limit, $order->stopPrice, $order->clientId,
        ));
    }

    public function cancelOrder(string $externalId): void
    {
        $this->gateway->cancel($externalId);
    }

    public function getBalances(): array
    {
        return $this->gateway->balances();
    }

    public function supports(Market $market): bool
    {
        return $market === Market::B3;
    }
}
