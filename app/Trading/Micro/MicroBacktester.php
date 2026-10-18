<?php

namespace App\Trading\Micro;

use App\Enums\MicroRule;

/**
 * Replays an entry rule over stored 1-minute candles, minute by minute, with
 * the engine's own reading (MicroRules) and exits. Each decision sees only the
 * candles closed up to that minute; the buy fills at the next candle's open
 * plus slippage; the target is a resting limit sell; the stop and the time
 * limit sell at market minus slippage. When the stop and the target fall in
 * the same candle, the stop counts (the worse case). One trade at a time per
 * pair, with the engine's pause after a stop.
 */
class MicroBacktester
{
    public function __construct(private readonly MicroRules $rules) {}

    /**
     * With `random` on, entries happen at random minutes (about 1 in 30) with
     * the rule's own exits: the baseline the rule has to beat.
     *
     * @param  list<array{t: int, open: float, high: float, low: float, close: float}>  $candles  oldest first, t in Unix seconds
     * @param  array{net_margin_pct: float, fee_rate: float, slippage_bps: float, cooldown_minutes: int, random?: bool, seed?: int}  $options
     * @return list<array{opened_at: int, exit: string, net_pct: float, minutes: int}>
     */
    public function simulate(MicroRule $rule, array $candles, array $options): array
    {
        $feePct = $options['fee_rate'] * 100;
        $slip = $options['slippage_bps'] / 10_000;
        $roundTrip = 2 * $feePct + 2 * $options['slippage_bps'] / 100;
        $gross = $options['net_margin_pct'] + $roundTrip;
        $lookback = $this->rules->lookbackMinutes($rule);
        $random = $options['random'] ?? false;

        if ($random) {
            mt_srand($options['seed'] ?? 42);
        }

        $trades = [];
        $count = count($candles);
        $resumeAt = 0;

        for ($t = 0; $t < $count - 1; $t++) {
            if ($t < $resumeAt || ($random && mt_rand(1, 30) !== 1)) {
                continue;
            }

            $window = array_slice($candles, max(0, $t - $lookback + 1), min($lookback, $t + 1));
            $setup = $this->rules->read($rule, $window, $gross, $roundTrip);

            if ($setup === null || (! $random && ! $setup->entry)) {
                continue;
            }

            $exits = $this->rules->exits($setup, $gross);
            $first = $candles[$t + 1];
            $entry = $first['open'] * (1 + $slip);
            $target = $entry * (1 + $exits['target_pct'] / 100);
            $stop = $entry * (1 - $exits['stop_pct'] / 100);
            $deadline = $first['t'] + $exits['max_hold_minutes'] * 60;
            $closed = null;

            for ($i = $t + 1; $i < $count; $i++) {
                $candle = $candles[$i];

                if ($candle['low'] <= $stop) {
                    // A gap below the stop sells at the open, not at the stop.
                    $closed = ['exit' => 'stop', 'price' => min($stop, $candle['open']) * (1 - $slip), 'at' => $i];
                } elseif ($candle['high'] >= $target) {
                    $closed = ['exit' => 'target', 'price' => $target, 'at' => $i];
                } elseif ($deadline <= $candle['t'] + 60) {
                    $closed = ['exit' => 'time', 'price' => $candle['close'] * (1 - $slip), 'at' => $i];
                }

                if ($closed !== null) {
                    break;
                }
            }

            if ($closed === null) {
                break; // the data ended with the trade still open: it has no result
            }

            $trades[] = [
                'opened_at' => $first['t'],
                'exit' => $closed['exit'],
                'net_pct' => ($closed['price'] / $entry - 1) * 100 - 2 * $feePct,
                'minutes' => intdiv($candles[$closed['at']]['t'] - $first['t'], 60) + 1,
            ];

            $resumeAt = $closed['at'] + 1 + ($closed['exit'] === 'stop' ? $options['cooldown_minutes'] : 0);
        }

        return $trades;
    }

    /**
     * Count, hit rate, exits, mean and sum in percent, and the lower bound of the
     * 95% interval of the mean (normal approximation).
     *
     * @param  list<array{opened_at: int, exit: string, net_pct: float, minutes: int}>  $trades
     * @return array{trades: int, wins: int, win_rate: float, target: int, stop: int, time: int, mean_pct: float, sum_pct: float, lower95_pct: float|null}
     */
    public function summarize(array $trades): array
    {
        $n = count($trades);
        $values = array_column($trades, 'net_pct');
        $mean = $n > 0 ? array_sum($values) / $n : 0.0;
        $variance = $n > 1 ? array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / ($n - 1) : 0.0;
        $exits = array_count_values(array_column($trades, 'exit'));
        $wins = count(array_filter($values, fn (float $v) => $v > 0));

        return [
            'trades' => $n,
            'wins' => $wins,
            'win_rate' => $n > 0 ? round($wins / $n * 100, 1) : 0.0,
            'target' => $exits['target'] ?? 0,
            'stop' => $exits['stop'] ?? 0,
            'time' => $exits['time'] ?? 0,
            'mean_pct' => round($mean, 4),
            'sum_pct' => round(array_sum($values), 4),
            'lower95_pct' => $n > 1 ? round($mean - 1.96 * sqrt($variance) / sqrt($n), 4) : null,
        ];
    }
}
