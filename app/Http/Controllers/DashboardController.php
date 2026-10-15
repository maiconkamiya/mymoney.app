<?php

namespace App\Http\Controllers;

use App\Enums\Market;
use App\MarketData\CollectorStatus;
use App\Models\Asset;
use App\Models\Candle;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(CollectorStatus $status): Response
    {
        $limit = (int) config('market.collector.candles_on_load');

        $assets = Asset::query()
            ->where('market', Market::Crypto)
            ->where('active', true)
            ->orderBy('symbol')
            ->get()
            ->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'symbol' => $asset->symbol,
                'quote_currency' => $asset->quote_currency,
                'tick_size' => $asset->tick_size,
                'candles' => $asset->candles()
                    ->where('timeframe', '1m')
                    ->latest('open_at')
                    ->limit($limit)
                    ->get()
                    ->reverse()
                    ->values()
                    ->map(fn (Candle $candle) => [
                        'time' => $candle->open_at->getTimestamp(),
                        'open' => (float) $candle->open,
                        'high' => (float) $candle->high,
                        'low' => (float) $candle->low,
                        'close' => (float) $candle->close,
                        'volume' => (float) $candle->volume,
                    ]),
            ]);

        return Inertia::render('Dashboard', [
            'assets' => $assets,
            'collector' => $status->snapshot(),
        ]);
    }
}
