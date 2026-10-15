<?php

namespace App\Trading\B3;

use App\Models\Asset;

/**
 * Lot and tick rules of the B3 cash equity market. The standard lot is 100
 * shares; the fractional market (tickers ending in F) trades 1 to 99 shares.
 */
class B3Rules
{
    public const STANDARD_LOT = 100;

    public const TICK = 0.01;

    public static function isFractional(string $symbol): bool
    {
        return (bool) preg_match('/^[A-Z]{4}\d{1,2}F$/', strtoupper($symbol));
    }

    public static function lotSizeFor(string $symbol): int
    {
        return self::isFractional($symbol) ? 1 : self::STANDARD_LOT;
    }

    /**
     * Round down to whole lots; the fractional market accepts at most 99 shares per order.
     */
    public function normalizeQuantity(Asset $asset, float $qty): float
    {
        $lot = (float) $asset->lot_size > 0 ? (float) $asset->lot_size : self::lotSizeFor($asset->symbol);
        $rounded = floor(round($qty / $lot, 8)) * $lot;

        if (self::isFractional($asset->symbol)) {
            return min($rounded, 99.0);
        }

        return $rounded;
    }

    public function normalizePrice(Asset $asset, float $price): float
    {
        $tick = (float) $asset->tick_size > 0 ? (float) $asset->tick_size : self::TICK;

        return round(round($price / $tick) * $tick, 8);
    }
}
