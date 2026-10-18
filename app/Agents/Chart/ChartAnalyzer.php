<?php

namespace App\Agents\Chart;

/**
 * Deterministic chart reading done before the Technical agent's model runs:
 * trend, EMAs, RSI, ATR, relative volume, support/resistance and breakouts on
 * several timeframes aggregated from 1-minute bars. A timeframe without enough
 * bars is reported as omitted, never filled in with guessed levels.
 *
 * @phpstan-import-type Bar from Timeframes
 */
class ChartAnalyzer
{
    /** Bars a timeframe needs before its indicators and levels mean anything. */
    public const MIN_BARS = 30;

    /** Bars of history kept per timeframe when looking for pivots. */
    private const LOOKBACK = 200;

    /** Latest bars checked for a breakout; pivots come from the bars before them. */
    private const BREAKOUT_WINDOW = 3;

    /**
     * @param  list<Bar>  $bars  1-minute bars, oldest first, none after $now
     * @param  int  $now  unix seconds
     * @return array{usable: bool, timeframes: array<string, array<string, mixed>>, omitted: array<string, string>, alignment: string|null, risk_reference: array<string, mixed>|null, extension: array<string, mixed>|null}
     */
    public function analyze(array $bars, int $now): array
    {
        $timeframes = [];
        $series = [];
        $omitted = [];

        foreach (Timeframes::MINUTES as $label => $minutes) {
            $aggregated = Timeframes::aggregate($bars, $minutes);

            if (count($aggregated) < self::MIN_BARS) {
                $omitted[$label] = 'histórico insuficiente: '.count($aggregated).' barras de '.self::MIN_BARS.' necessárias';

                continue;
            }

            $series[$label] = array_slice($aggregated, -self::LOOKBACK);
            $timeframes[$label] = $this->timeframe($series[$label], $minutes, $now);
        }

        return [
            'usable' => $timeframes !== [],
            'timeframes' => $timeframes,
            'omitted' => $omitted,
            'alignment' => $timeframes === [] ? null : $this->alignment($timeframes),
            'risk_reference' => $this->riskReference($timeframes),
            'extension' => $this->extension($timeframes, $series, $now),
        ];
    }

    /**
     * @param  list<Bar>  $bars
     * @return array<string, mixed>
     */
    private function timeframe(array $bars, int $minutes, int $now): array
    {
        $closes = array_column($bars, 'c');
        $price = $closes[count($closes) - 1];
        $atr = Indicators::atr($bars) ?? 0.0;

        $ema9 = Indicators::ema($closes, 9);
        $ema21 = Indicators::ema($closes, 21);
        $ema50 = Indicators::ema($closes, 50);

        // The newest bar is usually still forming, so its volume says little.
        $closed = $bars[count($bars) - 1]['t'] + $minutes * 60 <= $now ? $bars : array_slice($bars, 0, -1);

        $tolerance = max($atr * 0.5, $price * 0.001);
        $history = array_slice($bars, 0, -self::BREAKOUT_WINDOW);
        $levels = array_values(array_filter(Levels::find($history, $tolerance), fn (array $level) => $level['touches'] >= 2));

        $relativeVolume = Indicators::relativeVolume($closed);

        return [
            'bars' => count($bars),
            'close' => $this->round($price, $price),
            'trend' => $this->trend($price, $ema9, $ema21, $ema50),
            'ema9' => $this->round($ema9, $price),
            'ema21' => $this->round($ema21, $price),
            'ema50' => $this->round($ema50, $price),
            'rsi' => ($rsi = Indicators::rsi($closes)) === null ? null : round($rsi, 1),
            'atr' => $this->round($atr, $price),
            'atr_pct' => $price > 0 ? round($atr / $price * 100, 3) : null,
            'relative_volume' => $relativeVolume === null ? null : round($relativeVolume, 2),
            'range_high' => $this->round($this->extreme($bars, 'h'), $price),
            'range_low' => $this->round($this->extreme($bars, 'l'), $price),
            'supports' => $this->nearest($levels, $price, below: true),
            'resistances' => $this->nearest($levels, $price, below: false),
            'consolidation' => ($range = Levels::consolidation($bars, $atr)) === null ? null : [
                'low' => $this->round($range['low'], $price),
                'high' => $this->round($range['high'], $price),
                'bars' => $range['bars'],
            ],
            'events' => $this->events($bars, $levels, $atr, $relativeVolume),
        ];
    }

    private function trend(float $price, ?float $ema9, ?float $ema21, ?float $ema50): string
    {
        if ($ema9 === null || $ema21 === null) {
            return 'lateral';
        }

        $slow = $ema50 ?? $ema21;

        if ($ema9 > $ema21 && $ema21 >= $slow && $price > $ema21) {
            return 'alta';
        }

        if ($ema9 < $ema21 && $ema21 <= $slow && $price < $ema21) {
            return 'baixa';
        }

        return 'lateral';
    }

    /**
     * @param  list<array{price: float, touches: int, last_t: int}>  $levels
     * @return list<array{price: float, touches: int}>
     */
    private function nearest(array $levels, float $price, bool $below): array
    {
        $side = array_values(array_filter($levels, fn (array $level) => $below ? $level['price'] <= $price : $level['price'] > $price));
        usort($side, fn (array $a, array $b) => $below ? $b['price'] <=> $a['price'] : $a['price'] <=> $b['price']);

        return array_map(
            fn (array $level) => ['price' => $this->round($level['price'], $price), 'touches' => $level['touches']],
            array_slice($side, 0, 3),
        );
    }

    /**
     * Breakouts, false breakouts and tests of a level, read from the newest bars
     * against levels that existed before them.
     *
     * @param  list<Bar>  $bars
     * @param  list<array{price: float, touches: int, last_t: int}>  $levels
     * @return list<array<string, mixed>>
     */
    private function events(array $bars, array $levels, float $atr, ?float $relativeVolume): array
    {
        if ($levels === [] || $atr <= 0) {
            return [];
        }

        $price = $bars[count($bars) - 1]['c'];
        $window = array_slice($bars, -self::BREAKOUT_WINDOW);
        $reference = $bars[count($bars) - self::BREAKOUT_WINDOW - 1]['c'];
        $buffer = $atr * 0.1;
        $volumeConfirmed = $relativeVolume !== null && $relativeVolume >= 1.2;

        $found = [];

        foreach ($levels as $level) {
            $line = $level['price'];
            $type = null;

            if ($reference < $line) {
                $crossed = array_filter($window, fn (array $bar) => $bar['c'] > $line + $buffer) !== [];

                if ($crossed) {
                    $type = $price > $line ? 'rompimento_alta' : 'falso_rompimento_alta';
                } elseif ($price <= $line && $line - $this->extreme($window, 'h') <= $atr * 0.5) {
                    $type = 'teste_resistencia';
                }
            } elseif ($reference > $line) {
                $crossed = array_filter($window, fn (array $bar) => $bar['c'] < $line - $buffer) !== [];

                if ($crossed) {
                    $type = $price < $line ? 'rompimento_baixa' : 'falso_rompimento_baixa';
                } elseif ($price >= $line && $this->extreme($window, 'l') - $line <= $atr * 0.5) {
                    $type = 'teste_suporte';
                }
            }

            if ($type !== null && ($found[$type]['touches'] ?? 0) < $level['touches']) {
                $found[$type] = [
                    'type' => $type,
                    'level' => $this->round($line, $price),
                    'touches' => $level['touches'],
                    'volume_confirmed' => str_starts_with($type, 'rompimento') ? $volumeConfirmed : null,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<string, array<string, mixed>>  $timeframes
     */
    private function alignment(array $timeframes): string
    {
        $trends = array_unique(array_column($timeframes, 'trend'));

        return count($trends) === 1 ? $trends[0] : 'misto';
    }

    /**
     * ATR of the most useful timeframe for sizing a stop (1 h, else 15 m, else 5 m).
     *
     * @param  array<string, array<string, mixed>>  $timeframes
     * @return array<string, mixed>|null
     */
    private function riskReference(array $timeframes): ?array
    {
        foreach (['1h', '15m', '5m'] as $label) {
            if (isset($timeframes[$label]) && $timeframes[$label]['atr'] > 0) {
                $atr = $timeframes[$label]['atr'];

                return [
                    'timeframe' => $label,
                    'atr' => $atr,
                    'stop_distance_min' => $this->round($atr * 1.5, $timeframes[$label]['close']),
                    'stop_distance_max' => $this->round($atr * 3, $timeframes[$label]['close']),
                ];
            }
        }

        return null;
    }

    /**
     * How far a rise can still go and how stretched it already is, read on the
     * same timeframe that sizes the stop (1 h, else 15 m, else 5 m).
     *
     * The probable ceiling is the nearest resistance with two or more touches
     * on any timeframe; without one, the high of the 1 h or 4 h range; without
     * that, the price plus 2 ATR. reward_risk compares the room up to the
     * ceiling with the reference stop of 1.5 ATR.
     *
     * @param  array<string, array<string, mixed>>  $timeframes
     * @param  array<string, list<Bar>>  $series
     * @return array<string, mixed>|null
     */
    private function extension(array $timeframes, array $series, int $now): ?array
    {
        $label = null;

        foreach (['1h', '15m', '5m'] as $candidate) {
            if (isset($timeframes[$candidate]) && $timeframes[$candidate]['atr'] > 0) {
                $label = $candidate;

                break;
            }
        }

        if ($label === null) {
            return null;
        }

        $reading = $timeframes[$label];
        $price = (float) $reading['close'];
        $atr = (float) $reading['atr'];

        [$ceiling, $source] = $this->ceiling($timeframes, $price, $atr);
        $bars = $series[$label];
        $recentLow = $this->extreme(array_slice($bars, -20), 'l');

        return [
            'timeframe' => $label,
            'atr' => $this->round($atr, $price),
            'ceiling' => $this->round($ceiling, $price),
            'ceiling_source' => $source,
            'room_pct' => round(($ceiling - $price) / $price * 100, 2),
            'room_atr' => round(($ceiling - $price) / $atr, 2),
            'reward_risk' => round(($ceiling - $price) / ($atr * 1.5), 2),
            'ema21_distance_atr' => $reading['ema21'] === null ? null : round(($price - $reading['ema21']) / $atr, 2),
            'move_from_low_atr' => round(($price - $recentLow) / $atr, 2),
            'volume_fading' => $this->volumeFading($bars, Timeframes::MINUTES[$label], $now),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $timeframes
     * @return array{0: float, 1: string}
     */
    private function ceiling(array $timeframes, float $price, float $atr): array
    {
        $resistances = [];

        foreach ($timeframes as $reading) {
            foreach ($reading['resistances'] as $level) {
                if ($level['price'] > $price && $level['touches'] >= 2) {
                    $resistances[] = (float) $level['price'];
                }
            }
        }

        if ($resistances !== []) {
            return [min($resistances), 'resistencia'];
        }

        foreach (['1h', '4h'] as $label) {
            if (isset($timeframes[$label]) && $timeframes[$label]['range_high'] > $price) {
                return [(float) $timeframes[$label]['range_high'], 'maxima_'.$label];
            }
        }

        return [$price + 2 * $atr, 'atr'];
    }

    /**
     * True when the price rose over the last 5 closed bars on less volume than
     * the 15 before them (under 80 %): a rise running out of buyers.
     *
     * @param  list<Bar>  $bars
     */
    private function volumeFading(array $bars, int $minutes, int $now): ?bool
    {
        $closed = $bars[count($bars) - 1]['t'] + $minutes * 60 <= $now ? $bars : array_slice($bars, 0, -1);

        if (count($closed) < 21) {
            return null;
        }

        $recent = array_slice($closed, -5);
        $before = array_slice($closed, -20, 15);
        $average = fn (array $list) => array_sum(array_column($list, 'v')) / count($list);

        if ($average($before) <= 0) {
            return null;
        }

        $rising = $recent[4]['c'] > $closed[count($closed) - 6]['c'];

        return $rising && $average($recent) < $average($before) * 0.8;
    }

    /**
     * Highest high ('h') or lowest low ('l') of the bars.
     *
     * @param  list<Bar>  $bars
     */
    private function extreme(array $bars, string $side): float
    {
        $value = $side === 'h' ? -INF : INF;

        foreach ($bars as $bar) {
            $value = $side === 'h' ? max($value, $bar['h']) : min($value, $bar['l']);
        }

        return $value;
    }

    private function round(?float $value, float $reference): ?float
    {
        if ($value === null) {
            return null;
        }

        return round($value, $reference >= 100 ? 2 : ($reference >= 1 ? 4 : 8));
    }
}
