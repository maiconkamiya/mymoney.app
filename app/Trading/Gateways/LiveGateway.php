<?php

namespace App\Trading\Gateways;

use App\Models\BrokerCredential;
use App\Trading\Data\OrderResult;

/**
 * A gateway that talks to a real account: it is bound to one credential and
 * can report the state of an order it sent, for reconciliation.
 */
interface LiveGateway extends OrderGateway
{
    public function using(BrokerCredential $credential): static;

    public function fetch(string $externalId): OrderResult;
}
