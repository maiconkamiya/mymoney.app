<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Models\Portfolio;
use App\Trading\B3\B3Rules;
use App\Trading\Contracts\Broker;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Gateways\LiveGateway;
use App\Trading\Gateways\OrderGateway;
use App\Trading\Live\LiveLimits;
use App\Trading\Live\LiveTradingException;
use App\Trading\MarketHours;

/**
 * Live B3 execution. Session, lot and tick are checked here so the gateway
 * only ever receives an order the exchange would accept. The default gateway
 * refuses everything. For a real portfolio (built by the BrokerManager) every
 * call re-checks the live guard rails and binds the verified credential to a
 * `LiveGateway` such as Mt5HttpGateway.
 */
class Mt5Broker implements Broker
{
    public function __construct(
        private readonly OrderGateway $gateway,
        private readonly MarketHours $hours,
        private readonly B3Rules $rules = new B3Rules,
        private readonly ?Portfolio $portfolio = null,
        private readonly ?LiveLimits $limits = null,
    ) {}

    public function placeOrder(OrderRequest $order): OrderResult
    {
        $gateway = $this->gateway();
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

        return $gateway->send(new OrderRequest(
            $asset, $order->side, $qty, $order->type, $limit, $order->stopPrice, $order->clientId,
        ));
    }

    public function cancelOrder(string $externalId): void
    {
        $this->gateway()->cancel($externalId);
    }

    public function getOrder(string $externalId): OrderResult
    {
        $gateway = $this->gateway();

        if (! $gateway instanceof LiveGateway) {
            throw new LiveTradingException('O canal de ordens da B3 não suporta conciliação.');
        }

        return $gateway->fetch($externalId);
    }

    public function getBalances(): array
    {
        return $this->gateway()->balances();
    }

    public function supports(Market $market): bool
    {
        return $market === Market::B3;
    }

    private function gateway(): OrderGateway
    {
        if ($this->portfolio === null || ! $this->portfolio->isLive()) {
            return $this->gateway;
        }

        $credential = ($this->limits ?? app(LiveLimits::class))->assertCanSend($this->portfolio, $this->portfolio->brokerCredential);

        if ($credential->broker !== 'mt5') {
            throw new LiveTradingException('A credencial da carteira não é do MT5.');
        }

        if (! $this->gateway instanceof LiveGateway) {
            return $this->gateway;
        }

        return $this->gateway->using($credential);
    }
}
