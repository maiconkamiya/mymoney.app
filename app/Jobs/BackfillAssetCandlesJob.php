<?php

namespace App\Jobs;

use App\Enums\Market;
use App\MarketData\BinanceKlines;
use App\MarketData\BrapiB3;
use App\MarketData\CandleAggregator;
use App\Models\Asset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traz os candles recentes de um ativo recém-cadastrado, para o gráfico e os agentes
 * não começarem vazios. Os comandos de sincronização continuam a partir daí.
 */
class BackfillAssetCandlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $assetId) {}

    public function handle(BinanceKlines $binance, BrapiB3 $brapi, CandleAggregator $aggregator): void
    {
        $asset = Asset::query()->find($this->assetId);

        if ($asset === null || ! $asset->active) {
            return;
        }

        $candles = $asset->market === Market::Crypto
            ? $binance->latest($asset->symbol, 240)
            : $brapi->candles($asset->symbol);

        foreach ($candles as $candle) {
            $aggregator->upsertKline($asset, $candle);
        }
    }
}
