<?php

namespace App\Trading\Gateways;

use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;

/**
 * Transport to a real B3 order channel (MetaTrader 5 bridge, broker API).
 * The broker validates session, lot and tick before calling it, so an
 * implementation only translates and sends.
 */
interface OrderGateway
{
    public function send(OrderRequest $order): OrderResult;

    public function cancel(string $externalId): void;

    /**
     * @return array<string, float>
     */
    public function balances(): array;
}
