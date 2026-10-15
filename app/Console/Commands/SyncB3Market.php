<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\MarketData\B3Fundamentals;
use App\MarketData\BrapiB3;
use App\MarketData\CandleAggregator;
use App\Models\Asset;
use App\Trading\MarketHours;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:sync-b3 {--fundamentals : Refresh fundamentals instead of candles} {--force : Fetch candles outside the session}')]
#[Description('Fetch B3 candles (or fundamentals) for active B3 assets from brapi.dev')]
class SyncB3Market extends Command
{
    public function handle(BrapiB3 $brapi, CandleAggregator $aggregator, B3Fundamentals $fundamentals, MarketHours $hours): int
    {
        $assets = Asset::query()->where('market', Market::B3)->where('active', true)->get();

        if ($this->option('fundamentals')) {
            foreach ($assets as $asset) {
                if (! $fundamentals->refresh($asset)) {
                    $this->error("{$asset->symbol}: falha ao buscar fundamentos.");
                }
            }

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $hours->isOpen(Market::B3)) {
            return self::SUCCESS;
        }

        foreach ($assets as $asset) {
            try {
                foreach ($brapi->candles($asset->symbol) as $candle) {
                    $aggregator->upsertKline($asset, $candle);
                }
            } catch (Throwable $e) {
                $this->error("{$asset->symbol}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
