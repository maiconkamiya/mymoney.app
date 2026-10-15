<?php

namespace App\MarketData;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Public Binance market data over REST. Polling 1-minute klines is enough for
 * phase 1; the WebSocket stream can replace it without touching the rest.
 */
class BinanceKlines
{
    /**
     * @return list<array{open_at: Carbon, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function latest(string $symbol, int $limit = 5): array
    {
        $rows = Http::baseUrl('https://api.binance.com')
            ->timeout(10)
            ->get('/api/v3/klines', ['symbol' => $symbol, 'interval' => '1m', 'limit' => $limit])
            ->throw()
            ->json();

        return array_values(array_map(fn (array $row) => [
            'open_at' => Carbon::createFromTimestampMs($row[0]),
            'open' => (float) $row[1],
            'high' => (float) $row[2],
            'low' => (float) $row[3],
            'close' => (float) $row[4],
            'volume' => (float) $row[5],
        ], $rows));
    }
}
