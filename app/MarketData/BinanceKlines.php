<?php

namespace App\MarketData;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Public Binance market data over REST. Polling 1-minute klines is enough for
 * phase 1; the WebSocket stream can replace it without touching the rest.
 * Reads from the market-data-only host by default, which answers from
 * locations where api.binance.com returns 451.
 */
class BinanceKlines
{
    /**
     * @return list<array{open_at: Carbon, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function latest(string $symbol, int $limit = 5): array
    {
        return $this->fetch(['symbol' => $symbol, 'interval' => '1m', 'limit' => $limit]);
    }

    /**
     * The last `$limit` 1-hour klines, oldest first; the current hour is the last one.
     *
     * @return list<array{open_at: Carbon, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function hourly(string $symbol, int $limit = 48): array
    {
        return $this->fetch(['symbol' => $symbol, 'interval' => '1h', 'limit' => $limit]);
    }

    /**
     * The last `$limit` klines of any Binance interval (15m, 1h, 4h, 1d...), oldest first.
     *
     * @return list<array{open_at: Carbon, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function interval(string $symbol, string $interval, int $limit): array
    {
        return $this->fetch(['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit]);
    }

    /**
     * Up to 1000 klines starting at $from, oldest first (the Binance page size).
     *
     * @return list<array{open_at: Carbon, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function since(string $symbol, CarbonInterface $from, int $limit = 1000): array
    {
        return $this->fetch(['symbol' => $symbol, 'interval' => '1m', 'startTime' => $from->getTimestampMs(), 'limit' => $limit]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array{open_at: Carbon, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function fetch(array $query): array
    {
        $rows = Http::baseUrl(config('trading.binance.market_data_url'))
            ->timeout(10)
            ->get('/api/v3/klines', $query)
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
