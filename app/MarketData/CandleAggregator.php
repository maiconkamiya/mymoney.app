<?php

namespace App\MarketData;

use App\Models\Asset;
use App\Models\Candle;
use Carbon\CarbonInterface;

/**
 * Folds trades or klines into 1-minute candles.
 */
class CandleAggregator
{
    public function ingestTrade(Asset $asset, float $price, float $volume, CarbonInterface $at): Candle
    {
        $openAt = $at->copy()->startOfMinute();

        $candle = Candle::firstOrNew([
            'asset_id' => $asset->id,
            'timeframe' => '1m',
            'open_at' => $openAt,
        ]);

        if (! $candle->exists) {
            $candle->fill(['open' => $price, 'high' => $price, 'low' => $price, 'close' => $price, 'volume' => 0]);
        }

        $candle->fill([
            'high' => max((float) $candle->high, $price),
            'low' => min((float) $candle->low, $price),
            'close' => $price,
            'volume' => (float) $candle->volume + $volume,
        ])->save();

        return $candle;
    }

    /**
     * @param  array{open_at: CarbonInterface, open: float, high: float, low: float, close: float, volume: float}  $kline
     */
    public function upsertKline(Asset $asset, array $kline, string $timeframe = '1m'): Candle
    {
        return Candle::updateOrCreate(
            ['asset_id' => $asset->id, 'timeframe' => $timeframe, 'open_at' => $kline['open_at']],
            collect($kline)->except('open_at')->all(),
        );
    }
}
