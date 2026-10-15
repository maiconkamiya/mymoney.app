<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\MarketData\BinanceKlines;
use App\MarketData\CandleAggregator;
use App\Models\Asset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:sync-binance')]
#[Description('Fetch the latest 1-minute candles for active crypto assets from Binance')]
class SyncBinanceCandles extends Command
{
    public function handle(BinanceKlines $binance, CandleAggregator $aggregator): int
    {
        $assets = Asset::query()->where('market', Market::Crypto)->where('active', true)->get();

        foreach ($assets as $asset) {
            try {
                foreach ($binance->latest($asset->symbol) as $kline) {
                    $aggregator->upsertKline($asset, $kline);
                }
            } catch (Throwable $e) {
                $this->error("{$asset->symbol}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
