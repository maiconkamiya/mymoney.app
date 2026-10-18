<?php

namespace App\Explorer;

use App\Agents\Chart\Indicators;

/**
 * Regras determinísticas do explorador, sem rede nem banco: o filtro barato
 * sobre o ticker de 24 h e a nota da segunda passada com velas de 1 h.
 */
class OpportunityRanker
{
    private const LEVERAGED_SUFFIXES = ['UP', 'DOWN', 'BULL', 'BEAR'];

    /** Acima desta alta em 24 h a parte de momento da nota não cresce mais. */
    private const MOMENTUM_CAP_PCT = 8.0;

    /**
     * Pares que passam no filtro barato, com a nota prévia (momento e liquidez)
     * que escolhe quem ganha a segunda passada.
     *
     * @param  list<array{symbol: string, price: float, change_pct: float, quote_volume: float, bid: float, ask: float, high: float}>  $tickers
     * @param  array<string, string>  $bases  símbolo => moeda base dos pares spot USDT negociáveis
     * @return list<array{symbol: string, base: string, price: float, change_pct: float, quote_volume: float, spread_pct: float, prelim: float}>
     */
    public function filter(array $tickers, array $bases): array
    {
        $filters = config('explorer.filters');
        $baseSet = array_flip($bases);
        $passed = [];

        foreach ($tickers as $ticker) {
            $base = $bases[$ticker['symbol']] ?? null;

            if ($base === null
                || in_array($base, $filters['stable_bases'], true)
                || $this->isLeveraged($base, $baseSet)
                || $ticker['price'] <= 0
                || $ticker['bid'] <= 0
                || $ticker['ask'] < $ticker['bid']) {
                continue;
            }

            $spread = ($ticker['ask'] - $ticker['bid']) / (($ticker['ask'] + $ticker['bid']) / 2) * 100;

            if ($ticker['quote_volume'] < $filters['min_quote_volume']
                || $spread > $filters['max_spread_pct']
                || $ticker['change_pct'] < $filters['min_change_pct']
                || $ticker['change_pct'] > $filters['max_change_pct']) {
                continue;
            }

            $passed[] = [
                'symbol' => $ticker['symbol'],
                'base' => $base,
                'price' => $ticker['price'],
                'change_pct' => $ticker['change_pct'],
                'quote_volume' => $ticker['quote_volume'],
                'spread_pct' => round($spread, 4),
                'prelim' => ($this->momentum($ticker['change_pct']) + $this->liquidity($ticker['quote_volume'])) / 2,
            ];
        }

        usort($passed, fn (array $a, array $b) => $b['prelim'] <=> $a['prelim']);

        return $passed;
    }

    /**
     * Nota de 0 a 100 com velas de 1 h (a última é a hora em curso), ou null
     * quando falta histórico ou a alta já está esticada demais.
     *
     * @param  array{symbol: string, base: string, price: float, change_pct: float, quote_volume: float, spread_pct: float, prelim: float}  $pair
     * @param  list<array{open: float, high: float, low: float, close: float, volume: float}>  $hourly
     * @return array{score: float, volume_accel: float, breakout: bool, extension_atr: float, reasons: list<string>}|null
     */
    public function score(array $pair, array $hourly): ?array
    {
        // A hora em curso fica de fora: o volume dela ainda está incompleto.
        $closed = array_slice($hourly, 0, -1);

        if (count($closed) < 26) {
            return null;
        }

        $last = $closed[count($closed) - 1];
        $previous = array_slice($closed, -25, 24);
        $averageVolume = array_sum(array_column($previous, 'volume')) / count($previous);
        $accel = $averageVolume > 0 ? $last['volume'] / $averageVolume : 0.0;
        $breakout = $pair['price'] > max(0.0, ...array_column($previous, 'high'));

        $closes = array_column($hourly, 'close');
        $ema = Indicators::ema($closes, 21);
        $atr = Indicators::atr(array_map(fn (array $bar) => ['h' => $bar['high'], 'l' => $bar['low'], 'c' => $bar['close']], $hourly));

        if ($ema === null || $atr === null || $atr <= 0) {
            return null;
        }

        $extension = ($pair['price'] - $ema) / $atr;

        if ($extension > config('explorer.filters.max_extension_atr')) {
            return null;
        }

        $extensionScore = $extension < 0 ? 0.3 : 1 - min($extension / config('explorer.filters.max_extension_atr'), 1);

        $score = 100 * (
            0.20 * $this->momentum($pair['change_pct'])
            + 0.30 * $this->clamp(($accel - 1) / 3)
            + 0.20 * ($breakout ? 1 : 0)
            + 0.15 * $this->liquidity($pair['quote_volume'])
            + 0.15 * $extensionScore
        );

        $reasons = [sprintf('Alta de %s%% em 24 h com %s USDT negociados.', $this->number($pair['change_pct'], 1), $this->compact($pair['quote_volume']))];

        if ($accel >= 1.5) {
            $reasons[] = sprintf('Volume da última hora %sx a média de 24 h.', $this->number($accel, 1));
        }

        if ($breakout) {
            $reasons[] = 'Preço acima da máxima das 24 h anteriores.';
        }

        $reasons[] = $extension < 0
            ? 'Preço abaixo da média de 21 h: tendência ainda não confirmada.'
            : sprintf('%s ATR acima da média de 21 h: espaço antes de ficar esticado.', $this->number($extension, 1));

        return [
            'score' => round($score, 2),
            'volume_accel' => round($accel, 4),
            'breakout' => $breakout,
            'extension_atr' => round($extension, 4),
            'reasons' => $reasons,
        ];
    }

    /**
     * Token alavancado da Binance: a base sem o sufixo é outra moeda listada (BTCUP, ETHBEAR).
     *
     * @param  array<string, mixed>  $baseSet
     */
    private function isLeveraged(string $base, array $baseSet): bool
    {
        foreach (self::LEVERAGED_SUFFIXES as $suffix) {
            if (str_ends_with($base, $suffix) && strlen($base) > strlen($suffix) && isset($baseSet[substr($base, 0, -strlen($suffix))])) {
                return true;
            }
        }

        return false;
    }

    private function momentum(float $change): float
    {
        $min = config('explorer.filters.min_change_pct');

        return $this->clamp(($change - $min) / max(self::MOMENTUM_CAP_PCT - $min, 0.01));
    }

    private function liquidity(float $quoteVolume): float
    {
        $min = max(config('explorer.filters.min_quote_volume'), 1);

        return $this->clamp(log10(max($quoteVolume, 1) / $min) / 2);
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    private function compact(float $value): string
    {
        return $value >= 1_000_000
            ? $this->number($value / 1_000_000, 1).' mi'
            : $this->number($value / 1_000, 0).' mil';
    }
}
