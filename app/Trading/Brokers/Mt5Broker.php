<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Trading\Contracts\Broker;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use LogicException;

/**
 * Live execution through Mt5. Planned for phase 5 of the spec; until then every
 * call refuses so a misconfigured portfolio can never send a real order.
 */
class Mt5Broker implements Broker
{
    public function placeOrder(OrderRequest $order): OrderResult
    {
        throw new LogicException('Mt5Broker ainda não foi implementado.');
    }

    public function cancelOrder(string $externalId): void
    {
        throw new LogicException('Mt5Broker ainda não foi implementado.');
    }

    public function getBalances(): array
    {
        throw new LogicException('Mt5Broker ainda não foi implementado.');
    }

    public function supports(Market $market): bool
    {
        return $market === Market::B3;
    }
}
