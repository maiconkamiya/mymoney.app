<?php

namespace App\Trading\Micro;

use App\Models\Asset;
use App\Models\Candle;
use Illuminate\Support\Facades\Cache;

/**
 * How often the pair, in the last 30 days of 1-minute candles, rose as far as
 * the sale price within the minutes left, starting from moments with a
 * volatility like now (average 1-minute range between 2/3 and 1.5 times the
 * current one). A frequency, not a forecast: no model is called.
 */
class MicroTargetOdds
{
    /** Below this many comparable moments the whole history is used. */
    private const MIN_SIMILAR = 300;

    /**
     * @return array{pct: int|null, distance_pct: float, distance_atr: float|null, minutes: int, samples: int, reason: string}
     */
    public function estimate(Asset $asset, float $price, float $target, int $minutes): array
    {
        $minutes = max(1, $minutes);
        $distance = ($target - $price) / $price * 100;

        if ($distance <= 0) {
            return ['pct' => 100, 'distance_pct' => round($distance, 4), 'distance_atr' => null, 'minutes' => $minutes, 'samples' => 0, 'reason' => 'O preço já está no valor da venda ou acima: a ordem deve executar.'];
        }

        $key = sprintf('micro:odds:%d:%.2f:%d:%s', $asset->id, $distance, $minutes, now()->format('YmdHi'));

        return Cache::remember($key, now()->addMinutes(2), fn () => $this->compute($asset, $distance, $minutes));
    }

    /**
     * @return array{pct: int|null, distance_pct: float, distance_atr: float|null, minutes: int, samples: int, reason: string}
     */
    private function compute(Asset $asset, float $distance, int $minutes): array
    {
        $window = (int) config('trading.micro.atr_window');
        $rows = Candle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->where('open_at', '>=', now()->subDays(30))
            ->orderBy('open_at')
            ->toBase()
            ->get(['high', 'low', 'close']);

        $high = [];
        $close = [];
        $range = [];

        foreach ($rows as $row) {
            $c = (float) $row->close;

            if ($c <= 0) {
                continue;
            }

            $high[] = (float) $row->high;
            $close[] = $c;
            $range[] = ((float) $row->high - (float) $row->low) / $c * 100;
        }

        $n = count($close);
        $empty = ['pct' => null, 'distance_pct' => round($distance, 4), 'distance_atr' => null, 'minutes' => $minutes, 'samples' => 0];

        if ($n < $window + $minutes + 100) {
            return $empty + ['reason' => 'Poucas velas de 1 minuto deste par para comparar (precisa de algumas horas de histórico).'];
        }

        // Average 1-minute range of the `$window` candles ending at each index.
        $sum = array_sum(array_slice($range, 0, $window));
        $atr = array_fill(0, $n, 0.0);
        $atr[$window - 1] = $sum / $window;

        for ($i = $window; $i < $n; $i++) {
            $sum += $range[$i] - $range[$i - $window];
            $atr[$i] = $sum / $window;
        }

        $current = $atr[$n - 1];

        // Highest high of the next `$minutes` candles after each index (sliding maximum, from the end).
        $ahead = array_fill(0, $n, 0.0);
        $deque = [];

        for ($i = $n - 1; $i >= 0; $i--) {
            $ahead[$i] = $deque === [] ? 0.0 : $high[$deque[0]];

            while ($deque !== [] && $high[end($deque)] <= $high[$i]) {
                array_pop($deque);
            }

            $deque[] = $i;

            if ($deque[0] >= $i + $minutes) {
                array_shift($deque);
            }
        }

        $factor = 1 + $distance / 100;
        $similar = ['hits' => 0, 'total' => 0];
        $all = ['hits' => 0, 'total' => 0];

        for ($i = $window - 1; $i < $n - $minutes; $i++) {
            $hit = $ahead[$i] >= $close[$i] * $factor;
            $all['total']++;
            $all['hits'] += (int) $hit;

            if ($current > 0 && $atr[$i] >= $current / 1.5 && $atr[$i] <= $current * 1.5) {
                $similar['total']++;
                $similar['hits'] += (int) $hit;
            }
        }

        $base = $similar['total'] >= self::MIN_SIMILAR ? $similar : $all;
        $pct = (int) round($base['hits'] / max($base['total'], 1) * 100);
        $atrMultiple = $current > 0 ? round($distance / $current, 2) : null;

        $reason = sprintf(
            'Em 30 dias, de %s momentos %s, o preço subiu %s%% em até %d min em %d%% das vezes. A distância é %s a faixa média de 1 minuto de agora.',
            number_format($base['total'], 0, ',', '.'),
            $base === $similar ? 'com volatilidade parecida com a de agora' : 'do par (poucos com volatilidade parecida)',
            number_format($distance, 2, ',', '.'),
            $minutes,
            $pct,
            $atrMultiple === null ? 'sem medida de' : number_format($atrMultiple, 1, ',', '.').' vezes',
        );

        return ['pct' => $pct, 'distance_pct' => round($distance, 4), 'distance_atr' => $atrMultiple, 'minutes' => $minutes, 'samples' => $base['total'], 'reason' => $reason];
    }
}
