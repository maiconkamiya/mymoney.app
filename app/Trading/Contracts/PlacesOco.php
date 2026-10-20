<?php

namespace App\Trading\Contracts;

use App\Trading\Data\OcoResult;
use App\Trading\Data\OrderRequest;

/**
 * A broker that takes a sell OCO: a limit sale at the target and a stop-limit
 * sale under the price, of the same coins. When one leg executes the
 * exchange cancels the other.
 */
interface PlacesOco
{
    /**
     * @param  OrderRequest  $target  the limit sale above the price
     * @param  OrderRequest  $stop  the sale at `stopPrice`, resting at `limitPrice` once triggered
     */
    public function placeOco(OrderRequest $target, OrderRequest $stop, string $clientId): OcoResult;
}
