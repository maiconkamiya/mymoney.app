<?php

namespace App\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * Estatísticas de 24 h de todos os pares numa chamada só (peso 80 de 6000 por minuto),
 * com o melhor preço de compra e de venda para medir o spread.
 */
class BinanceTickers
{
    /**
     * @return list<array{symbol: string, price: float, change_pct: float, quote_volume: float, bid: float, ask: float, high: float}>
     */
    public function all(): array
    {
        $rows = Http::baseUrl(config('trading.binance.market_data_url'))
            ->timeout(30)
            ->get('/api/v3/ticker/24hr')
            ->throw()
            ->json();

        $tickers = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['symbol'], $row['lastPrice'])) {
                continue;
            }

            $tickers[] = [
                'symbol' => (string) $row['symbol'],
                'price' => (float) $row['lastPrice'],
                'change_pct' => (float) ($row['priceChangePercent'] ?? 0),
                'quote_volume' => (float) ($row['quoteVolume'] ?? 0),
                'bid' => (float) ($row['bidPrice'] ?? 0),
                'ask' => (float) ($row['askPrice'] ?? 0),
                'high' => (float) ($row['highPrice'] ?? 0),
            ];
        }

        return $tickers;
    }
}
