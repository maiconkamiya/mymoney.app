<?php

namespace Tests\Concerns;

use App\Models\Asset;
use App\Models\Candle;

trait BuildsMarket
{
    /**
     * Write the current 1-minute candle so the price feed quotes $price as of now.
     */
    protected function quote(Asset $asset, float $price, ?\DateTimeInterface $at = null): void
    {
        Candle::create([
            'asset_id' => $asset->id,
            'timeframe' => '1m',
            'open_at' => ($at ?? now())->copy()->startOfMinute(),
            'open' => $price,
            'high' => $price,
            'low' => $price,
            'close' => $price,
            'volume' => 1,
        ]);
    }

    /**
     * Flat 1-minute history ending a minute before now, enough for the Technical agent to read a chart.
     */
    protected function history(Asset $asset, float $price, int $minutes = 240): void
    {
        for ($i = $minutes; $i >= 1; $i--) {
            $this->quote($asset, $price, now()->subMinutes($i));
        }
    }
}
