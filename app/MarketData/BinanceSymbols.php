<?php

namespace App\MarketData;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Catálogo público de pares da Binance (exchangeInfo): só pares spot negociáveis
 * com cotação em USDT, com os filtros de lote, preço e valor mínimo da ordem.
 * Lê do host de dados de mercado, que responde onde api.binance.com devolve 451.
 */
class BinanceSymbols
{
    private const QUOTE = 'USDT';

    /**
     * Pares cujo símbolo ou moeda base contém o texto, em ordem alfabética.
     *
     * @return list<array{symbol: string, base: string, lot_size: string, tick_size: string, min_qty: string, min_notional: string|null}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = strtoupper(trim($query));

        $matches = [];

        foreach ($this->catalog() as $pair) {
            if ($query === '' || str_contains($pair['symbol'], $query)) {
                $matches[] = $pair;
            }
        }

        return array_slice($matches, 0, $limit);
    }

    /**
     * O par exato, consultado direto na Binance (sem cache), ou null se não existir
     * ou não estiver negociável contra USDT.
     *
     * @return array{symbol: string, base: string, lot_size: string, tick_size: string, min_qty: string, min_notional: string|null}|null
     */
    public function find(string $symbol): ?array
    {
        $response = Http::baseUrl(config('trading.binance.market_data_url'))
            ->timeout(15)
            ->get('/api/v3/exchangeInfo', ['symbol' => strtoupper($symbol)]);

        if ($response->clientError()) {
            return null; // -1121 "Invalid symbol"
        }

        $row = $response->throw()->json('symbols.0');

        return is_array($row) ? $this->pair($row) : null;
    }

    /**
     * @return list<array{symbol: string, base: string, lot_size: string, tick_size: string, min_qty: string, min_notional: string|null}>
     */
    public function catalog(): array
    {
        return Cache::remember('binance.pares-usdt', now()->addHour(), function (): array {
            $rows = Http::baseUrl(config('trading.binance.market_data_url'))
                ->timeout(30)
                ->get('/api/v3/exchangeInfo', ['symbolStatus' => 'TRADING'])
                ->throw()
                ->json('symbols', []);

            $pairs = array_filter(array_map(fn (array $row) => $this->pair($row), $rows));
            usort($pairs, fn (array $a, array $b) => $a['symbol'] <=> $b['symbol']);

            return $pairs;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{symbol: string, base: string, lot_size: string, tick_size: string, min_qty: string, min_notional: string|null}|null
     */
    private function pair(array $row): ?array
    {
        if (($row['quoteAsset'] ?? null) !== self::QUOTE
            || ($row['status'] ?? null) !== 'TRADING'
            || ! ($row['isSpotTradingAllowed'] ?? false)) {
            return null;
        }

        $filters = [];

        foreach ($row['filters'] ?? [] as $filter) {
            if (isset($filter['filterType'])) {
                $filters[(string) $filter['filterType']] = $filter;
            }
        }

        $notional = $filters['NOTIONAL'] ?? $filters['MIN_NOTIONAL'] ?? [];

        if (! isset($row['symbol'], $row['baseAsset'])) {
            return null;
        }

        return [
            'symbol' => (string) $row['symbol'],
            'base' => (string) $row['baseAsset'],
            'lot_size' => (string) ($filters['LOT_SIZE']['stepSize'] ?? '0.00000001'),
            'tick_size' => (string) ($filters['PRICE_FILTER']['tickSize'] ?? '0.01'),
            'min_qty' => (string) ($filters['LOT_SIZE']['minQty'] ?? '0'),
            'min_notional' => isset($notional['minNotional']) ? (string) $notional['minNotional'] : null,
        ];
    }
}
