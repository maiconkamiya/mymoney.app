<?php

namespace App\MarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * B3 market data from brapi.dev: intraday candles and fundamentals for one
 * ticker per call. Swap this class to change provider; callers only use the
 * array shapes documented below.
 */
class BrapiB3
{
    public function hasToken(): bool
    {
        return filled(config('trading.b3_data.token'));
    }

    /**
     * Ticker brapi.dev uses for one of our symbols (indices differ, e.g. IBOV is ^BVSP).
     */
    public function tickerFor(string $symbol): string
    {
        return config('trading.b3_data.benchmark_tickers.'.$symbol, $symbol);
    }

    /**
     * @return list<array{open_at: Carbon, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function candles(string $symbol): array
    {
        $result = $this->fetch($symbol, [
            'interval' => config('trading.b3_data.interval'),
            'range' => config('trading.b3_data.range'),
        ]);

        $candles = [];

        foreach ($result['historicalDataPrice'] ?? [] as $row) {
            if (! isset($row['date'], $row['open'], $row['high'], $row['low'], $row['close'])) {
                continue; // brapi returns null OHLC for minutes without trades
            }

            $candles[] = [
                'open_at' => Carbon::createFromTimestamp((int) $row['date']),
                'open' => (float) $row['open'],
                'high' => (float) $row['high'],
                'low' => (float) $row['low'],
                'close' => (float) $row['close'],
                'volume' => (float) ($row['volume'] ?? 0),
            ];
        }

        return $candles;
    }

    /**
     * @return array{pe: ?float, eps: ?float, price_to_book: ?float, dividend_yield: ?float, market_cap: ?float, net_margin: ?float, return_on_equity: ?float, debt_to_equity: ?float, dividends: list<array{paid_at: ?string, amount: float, type: ?string}>}
     */
    public function fundamentals(string $symbol): array
    {
        $result = $this->fetch($symbol, [
            'fundamental' => 'true',
            'dividends' => 'true',
            'modules' => 'defaultKeyStatistics,financialData',
        ]);

        $stats = $result['defaultKeyStatistics'] ?? [];
        $financial = $result['financialData'] ?? [];

        $dividends = [];

        foreach (array_slice($result['dividendsData']['cashDividends'] ?? [], 0, 8) as $d) {
            $dividends[] = [
                'paid_at' => isset($d['paymentDate']) ? (string) $d['paymentDate'] : null,
                'amount' => (float) ($d['rate'] ?? 0),
                'type' => isset($d['label']) ? (string) $d['label'] : null,
            ];
        }

        return [
            'pe' => $this->number($result['priceEarnings'] ?? null),
            'eps' => $this->number($result['earningsPerShare'] ?? null),
            'price_to_book' => $this->number($stats['priceToBook'] ?? null),
            'dividend_yield' => $this->number($stats['dividendYield'] ?? null),
            'market_cap' => $this->number($result['marketCap'] ?? null),
            'net_margin' => $this->number($financial['profitMargins'] ?? null),
            'return_on_equity' => $this->number($financial['returnOnEquity'] ?? null),
            'debt_to_equity' => $this->number($financial['debtToEquity'] ?? null),
            'dividends' => $dividends,
        ];
    }

    /**
     * @param  array<string, string|null>  $query
     * @return array<string, mixed>
     */
    private function fetch(string $symbol, array $query): array
    {
        $token = config('trading.b3_data.token');

        $response = $this->http()
            ->get('/api/quote/'.rawurlencode($symbol), array_filter($query + ['token' => $token]))
            ->throw()
            ->json();

        return $response['results'][0] ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://brapi.dev')->timeout(15)->acceptJson();
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
