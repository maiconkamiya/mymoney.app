<?php

namespace App\Trading;

use App\Models\Asset;
use App\Models\Candle;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\Quote;

/**
 * Reads the last close from the most recent 1-minute candle written by the
 * market data worker.
 */
class CandlePriceFeed implements PriceFeed
{
    public function quote(Asset $asset): ?Quote
    {
        $candle = Candle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->latest('open_at')
            ->first();

        if ($candle === null) {
            return null;
        }

        // A candle still being built is as fresh as "now"; a closed one is as old as its close.
        $closesAt = $candle->open_at->copy()->addMinute();

        return new Quote((float) $candle->close, $closesAt->isFuture() ? now() : $closesAt);
    }
}
