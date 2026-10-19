<?php

namespace App\Explorer;

use App\Trading\BreakoutSignals;

/**
 * Escore de rompimento de alta, de 0 a 100, sobre as velas de 1 h que o
 * explorador já baixa: os sinais de App\Trading\BreakoutSignals (o mesmo
 * cálculo das regras de rompimento das micro operações), cada um virado numa
 * nota de 0 a 1 e somado com os pesos de `explorer.breakout.weights`. Sem rede,
 * sem banco e sem IA.
 */
class BreakoutScore
{
    /** Velas de 1 h da caixa (as horas antes da última fechada) e do contexto antes dela. */
    private const BOX_BARS = 12;

    private const CONTEXT_BARS = 24;

    /** Faixa média da caixa nesta fração do contexto, ou menos, vale compressão total. */
    private const FULL_SQUEEZE = 0.4;

    /** Volume da última hora nesta razão da média da caixa, ou mais, vale nota cheia. */
    private const FULL_VOLUME = 3.0;

    /** Toques na máxima da caixa que valem nota cheia na estrutura. */
    private const FULL_TOUCHES = 3;

    /** Faixa da última hora nesta razão da média do contexto, ou mais, vale expansão total. */
    private const FULL_EXPANSION = 2.5;

    public function __construct(private readonly BreakoutSignals $signals) {}

    /**
     * @param  list<array{high: float, low: float, close: float, volume?: float}>  $closed  velas fechadas, a mais antiga primeiro
     * @return array{breakout_score: float, breakout_components: array<string, array{score: float, value: float, weight: float}>}|null
     */
    public function score(array $closed): ?array
    {
        $signals = $this->signals->measure($closed, [
            'box' => self::BOX_BARS,
            'context' => self::CONTEXT_BARS,
            'volume_recent' => 1,
        ]);

        if ($signals === null) {
            return null;
        }

        $last = $closed[count($closed) - 1];
        $lastRange = ($last['high'] - $last['low']) / $signals['price'] * 100;
        $expansion = $signals['context_range_pct'] > 0 ? $lastRange / $signals['context_range_pct'] : 0.0;
        $boxHeight = $signals['high'] - $signals['low'];
        $position = $signals['broke'] ? 1.0 : ($boxHeight > 0 ? ($signals['price'] - $signals['low']) / $boxHeight : 0.0);
        $squeeze = is_finite($signals['squeeze']) ? $signals['squeeze'] : 1.0;

        $parts = [
            'compression' => [$this->clamp((1 - $squeeze) / (1 - self::FULL_SQUEEZE)), $squeeze],
            'volume' => [$this->clamp(($signals['volume_ratio'] - 1) / (self::FULL_VOLUME - 1)), $signals['volume_ratio']],
            'structure' => [
                0.6 * min($signals['touches'] / self::FULL_TOUCHES, 1) + 0.4 * ($signals['rising_lows'] ? 1 : 0),
                (float) $signals['touches'],
            ],
            // Valor: quanto o fechamento passou da máxima da caixa, em %; negativo = abaixo.
            'donchian' => [$this->clamp($position), -$signals['gap_pct']],
            'expansion' => [$this->clamp(($expansion - 1) / (self::FULL_EXPANSION - 1)), $expansion],
        ];

        $weights = config('explorer.breakout.weights');
        $total = 0.0;
        $components = [];

        foreach ($parts as $key => [$score, $value]) {
            $weight = (float) ($weights[$key] ?? 0);
            $total += $weight * $score;
            $components[$key] = ['score' => round($score, 4), 'value' => round($value, 4), 'weight' => $weight];
        }

        return [
            'breakout_score' => round(100 * $total / max(array_sum(array_map(floatval(...), $weights)), 0.0001), 2),
            'breakout_components' => $components,
        ];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
