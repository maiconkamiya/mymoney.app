<?php

namespace App\Trading\Micro;

/**
 * What one buy plus one sell costs, in percent of the traded value, and the
 * gross margin the target needs so the owner's net margin survives it.
 */
class MicroCosts
{
    /** Taxa nos dois lados mais o deslize dos dois lados. */
    public function roundTripPct(): float
    {
        $costs = config('trading.costs.crypto');

        return 2 * $costs['fee_rate'] * 100 + 2 * $costs['slippage_bps'] / 100;
    }

    public function grossMarginPct(float $netMarginPct): float
    {
        return round($netMarginPct + $this->roundTripPct(), 4);
    }
}
