<?php

namespace App\Trading\Contracts;

use App\Enums\Market;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;

/**
 * The only way an order leaves the system. Paper and live portfolios share
 * everything up to this call; the implementation is the single difference.
 */
interface Broker
{
    public function placeOrder(OrderRequest $order): OrderResult;

    public function cancelOrder(string $externalId): void;

    /**
     * Current state of a previously sent order, for reconciliation.
     */
    public function getOrder(string $externalId): OrderResult;

    /**
     * Free balance per currency as reported by the broker.
     *
     * @return array<string, float>
     */
    public function getBalances(): array;

    public function supports(Market $market): bool;
}
