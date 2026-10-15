<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\MarketData\BinanceKlines;
use App\MarketData\BinanceStream;
use App\MarketData\BinanceStreamHandler;
use App\MarketData\CandleAggregator;
use App\Models\Asset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:stream')]
#[Description('Keep the Binance 1-minute kline stream running, saving candles and broadcasting them live')]
class StreamBinance extends Command
{
    public function handle(BinanceStreamHandler $handler, BinanceStream $stream, BinanceKlines $binance, CandleAggregator $aggregator): int
    {
        $assets = Asset::query()->where('market', Market::Crypto)->where('active', true)->get();

        if ($assets->isEmpty()) {
            $this->error('Nenhum ativo cripto ativo. Rode o MarketSeeder.');

            return self::FAILURE;
        }

        $handler->track($assets);

        $stream->run(
            fn (string $line) => $this->line(now()->format('H:i:s').' '.$line),
            function () use ($assets, $binance, $aggregator): void {
                foreach ($assets as $asset) {
                    try {
                        foreach ($binance->latest($asset->symbol, (int) config('market.binance.backfill')) as $kline) {
                            $aggregator->upsertKline($asset, $kline);
                        }
                    } catch (Throwable $e) {
                        $this->error("{$asset->symbol}: {$e->getMessage()}");
                    }
                }
            },
        );

        return self::SUCCESS;
    }
}
