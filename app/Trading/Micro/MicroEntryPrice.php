<?php

namespace App\Trading\Micro;

use App\Models\Asset;

/**
 * The price the engine suggests paying, so the buy does not chase the last
 * tick: a pullback of a fraction of the pair's average 1-minute range below
 * the reference (the lower of the last close and the quote of now), never
 * below the support and never above the reference, rounded down to the tick.
 * Deterministic: the engine, the card, `micro:run --explain` and the backtest
 * all get the same number from the same reading.
 */
class MicroEntryPrice
{
    public function suggest(MicroSetup $setup, ?Asset $asset = null, ?float $quote = null): float
    {
        $reference = $quote !== null && $quote > 0 ? min($setup->price, $quote) : $setup->price;
        $pullback = $reference * (1 - (float) config('trading.micro.limit.pullback_atr') * $setup->atrPct / 100);
        $price = min($reference, max($pullback, $setup->support));
        $tick = (float) ($asset->tick_size ?? 0);

        return $tick > 0 ? round(floor(round($price / $tick, 8)) * $tick, 8) : $price;
    }

    /**
     * How far below the reference the suggestion sits, in percent.
     */
    public function discountPct(MicroSetup $setup, float $suggested, ?float $quote = null): float
    {
        $reference = $quote !== null && $quote > 0 ? min($setup->price, $quote) : $setup->price;

        return $reference > 0 ? ($reference - $suggested) / $reference * 100 : 0.0;
    }
}
