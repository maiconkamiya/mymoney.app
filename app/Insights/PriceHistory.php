<?php

namespace App\Insights;

use App\MarketData\BinanceKlines;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Histórico de preços de um par como na página do ativo da Binance: variação e
 * gráfico em 24h, 7d, 1m, 3m e 1y. Quatro chamadas públicas de velas (o 3m sai
 * das velas diárias do 1y), guardadas por alguns minutos.
 */
class PriceHistory
{
    /**
     * Intervalo e quantidade de velas de cada período; null = recorte das velas diárias do ano.
     */
    public const PERIODS = [
        '24h' => ['15m', 96],
        '7d' => ['1h', 168],
        '1m' => ['4h', 180],
        '3m' => null,
        '1y' => ['1d', 365],
    ];

    public function __construct(private readonly BinanceKlines $klines) {}

    /**
     * @return array<string, array{change_pct: float, change: float, high: float, low: float, open: float, close: float, points: list<array{0: int, 1: float}>}>|null
     *                                                                                                                                                                null quando a Binance não respondeu
     */
    public function for(string $symbol): ?array
    {
        try {
            return Cache::remember('historico-precos:'.$symbol, now()->addMinutes(max(1, (int) config('insights.history_cache_minutes'))), fn () => $this->fetch($symbol));
        } catch (Throwable $e) {
            Log::warning("Histórico de preços de {$symbol} falhou: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @return array<string, array{change_pct: float, change: float, high: float, low: float, open: float, close: float, points: list<array{0: int, 1: float}>}>
     */
    private function fetch(string $symbol): array
    {
        $candles = [];

        foreach (self::PERIODS as $period => $spec) {
            if ($spec !== null) {
                $candles[$period] = $this->klines->interval($symbol, $spec[0], $spec[1]);
            }
        }

        $candles['3m'] = array_slice($candles['1y'], -90);
        $history = [];

        foreach (array_keys(self::PERIODS) as $period) {
            $rows = $candles[$period];

            if ($rows === []) {
                continue;
            }

            $open = $rows[0]['open'];
            $close = $rows[count($rows) - 1]['close'];

            $history[$period] = [
                'change_pct' => $open > 0 ? round(($close - $open) / $open * 100, 2) : 0.0,
                'change' => $close - $open,
                'high' => max(array_column($rows, 'high')),
                'low' => min(array_column($rows, 'low')),
                'open' => $open,
                'close' => $close,
                'points' => array_map(fn (array $row) => [$row['open_at']->getTimestampMs(), $row['close']], $rows),
            ];
        }

        return $history;
    }
}
