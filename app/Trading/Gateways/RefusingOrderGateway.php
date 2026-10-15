<?php

namespace App\Trading\Gateways;

use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use LogicException;

/**
 * Default gateway: no real channel is wired, so a misconfigured portfolio
 * can never send a live order. Phase 5 swaps it through `trading.b3_gateway`.
 */
class RefusingOrderGateway implements OrderGateway
{
    public function send(OrderRequest $order): OrderResult
    {
        $this->refuse();
    }

    public function cancel(string $externalId): void
    {
        $this->refuse();
    }

    public function balances(): array
    {
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new LogicException('Nenhum canal de ordens da B3 está configurado.');
    }
}
