<?php

namespace App\Trading\Micro;

use App\Trading\BreakoutSignals;

/**
 * The two breakout rules, read from 1-minute candles with volume. The raw
 * signals come from BreakoutSignals; this class turns them into the entry
 * conditions and the exits. No model is called and nothing after the last
 * closed minute is seen.
 *
 * Both start from a box: the last `box_minutes` candles before the current
 * one. The box is compressed when its average 1-minute range is at most
 * `max_squeeze` times the average range of the `context_minutes` before it.
 *
 * "Rompimento confirmado" buys when the last candle closes above the box high
 * with `volume_ratio` times the box's average volume. Stop below the middle of
 * the box; target the box height above the entry.
 *
 * "Pressão antes do rompimento" buys before the break: compressed box, lows
 * rising (second half of the box above the first), the box high touched at
 * least `min_touches` times, the price within `near_box` of the box height
 * under it and the volume of the last minutes rising. Stop below the low of
 * the second half; target the box height above the box high.
 *
 * Every target is at least `min_target_costs` times the round-trip cost and
 * has to fit the move the pair usually makes in the time limit; every stop is
 * at least one round-trip cost below the entry.
 */
class MicroBreakoutAnalysis
{
    public function __construct(private readonly BreakoutSignals $signals) {}

    /**
     * @param  list<array{high: float, low: float, close: float, volume?: float}>  $candles  oldest first
     */
    public function confirmed(array $candles, float $roundTripPct): ?MicroSetup
    {
        $box = $this->signals->measure($candles);

        if ($box === null) {
            return null;
        }

        $config = config('trading.micro.breakout');
        $price = $box['price'];
        $loud = $box['volume_ratio'] >= (float) $config['volume_ratio'];

        $targetPct = max($box['height_pct'], (float) $config['min_target_costs'] * $roundTripPct);
        $stopPct = max(($price - $box['mid']) / $price * 100, $roundTripPct);

        $checks = [
            $this->squeezeCheck($box),
            ['key' => 'break', 'label' => 'Fechou acima da caixa', 'ok' => $box['broke'], 'detail' => sprintf('Fechamento %.2f%% %s da máxima de %d minutos.', abs($box['gap_pct']), $box['broke'] ? 'acima' : 'abaixo', $box['bars'])],
            ['key' => 'volume', 'label' => 'Volume alto', 'ok' => $loud, 'detail' => sprintf('Volume da vela %.1f vezes a média da caixa (mínimo %.1f).', $box['volume_ratio'], (float) $config['volume_ratio'])],
            $this->reachCheck($targetPct, $box),
        ];

        return $this->setup($box, $checks, $targetPct, $stopPct, sprintf('Rompeu a caixa de %d minutos com volume %.1f vezes a média.', $box['bars'], $box['volume_ratio']));
    }

    /**
     * @param  list<array{high: float, low: float, close: float, volume?: float}>  $candles  oldest first
     */
    public function pressure(array $candles, float $roundTripPct): ?MicroSetup
    {
        $box = $this->signals->measure($candles);

        if ($box === null) {
            return null;
        }

        $config = config('trading.micro.breakout');
        $price = $box['price'];
        $tested = $box['touches'] >= (int) $config['min_touches'];
        $near = $box['gap_pct'] >= 0 && $box['gap_pct'] <= $box['height_pct'] * (float) $config['near_box'];
        $rising = $box['volume_rise'] >= (float) $config['volume_rise'];

        // Measured move: the box height above the box high.
        $targetPct = max($box['gap_pct'] + $box['height_pct'], (float) $config['min_target_costs'] * $roundTripPct);
        $stopPct = max(($price - $box['second_low']) / $price * 100, $roundTripPct);

        $checks = [
            $this->squeezeCheck($box),
            ['key' => 'lows', 'label' => 'Mínimas subindo', 'ok' => $box['rising_lows'], 'detail' => sprintf('Mínima da segunda metade da caixa %.2f%% %s da primeira.', abs($box['second_low'] / $box['first_low'] - 1) * 100, $box['rising_lows'] ? 'acima' : 'abaixo')],
            ['key' => 'resistance', 'label' => 'Resistência testada, preço colado', 'ok' => $tested && $near, 'detail' => sprintf('%d toques na máxima (mínimo %d); preço %.2f%% abaixo dela.', $box['touches'], (int) $config['min_touches'], $box['gap_pct'])],
            ['key' => 'volume', 'label' => 'Volume subindo', 'ok' => $rising, 'detail' => sprintf('Volume dos últimos %d minutos %.1f vezes a média da caixa (mínimo %.1f).', (int) $config['volume_recent_minutes'], $box['volume_rise'], (float) $config['volume_rise'])],
            $this->reachCheck($targetPct, $box),
        ];

        return $this->setup($box, $checks, $targetPct, $stopPct, sprintf('Caixa de %d minutos comprimida, mínimas subindo e %d toques na resistência.', $box['bars'], $box['touches']));
    }

    /** Minutes of 1-minute candles both rules read. */
    public function lookbackMinutes(): int
    {
        $config = config('trading.micro.breakout');

        return (int) $config['box_minutes'] + (int) $config['context_minutes'] + 1;
    }

    /**
     * @param  array{bars: int, squeeze: float}  $box
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function squeezeCheck(array $box): array
    {
        $max = (float) config('trading.micro.breakout.max_squeeze');

        return ['key' => 'squeeze', 'label' => 'Faixa comprimida', 'ok' => $box['squeeze'] <= $max, 'detail' => sprintf('Velas dos últimos %d minutos com %.0f%% do tamanho das %d horas antes (máximo %.0f%%).', $box['bars'], $box['squeeze'] * 100, intdiv((int) config('trading.micro.breakout.context_minutes'), 60), $max * 100)];
    }

    /**
     * Typical move in the time limit: the context's average range times the square root of the minutes.
     *
     * @param  array{context_range_pct: float}  $box
     */
    private function reachPct(array $box): float
    {
        return $box['context_range_pct'] * sqrt((float) config('trading.micro.breakout.max_hold_minutes'));
    }

    /**
     * @param  array{context_range_pct: float}  $box
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function reachCheck(float $targetPct, array $box): array
    {
        $reach = $this->reachPct($box);

        return ['key' => 'reach', 'label' => 'Alvo alcançável', 'ok' => $targetPct <= $reach * (float) config('trading.micro.reach_factor'), 'detail' => sprintf('Alvo de %.2f%%; o par costuma andar %.2f%% em %d minutos.', $targetPct, $reach, (int) config('trading.micro.breakout.max_hold_minutes'))];
    }

    /**
     * @param  array{box: list<array{high: float, low: float, close: float, volume?: float}>, high: float, low: float, mid: float, price: float, context_range_pct: float}  $box
     * @param  list<array{key: string, label: string, ok: bool, detail: string}>  $checks
     */
    private function setup(array $box, array $checks, float $targetPct, float $stopPct, string $why): MicroSetup
    {
        $hold = (int) config('trading.micro.breakout.max_hold_minutes');
        $entry = ! in_array(false, array_column($checks, 'ok'), true);
        $reasons = $entry
            ? [sprintf('%s Alvo %.2f%%, stop %.2f%%, até %d minutos.', $why, $targetPct, $stopPct, $hold)]
            : array_values(array_map(fn (array $check) => $check['label'].': não. '.$check['detail'], array_filter($checks, fn (array $check) => ! $check['ok'])));

        // Average 1-minute range of the box, the volatility measure the screen shows for every rule.
        $atrPct = array_sum(array_map(fn (array $c) => ((float) $c['high'] - (float) $c['low']) / (float) $c['close'] * 100, $box['box'])) / max(1, count($box['box']));

        return new MicroSetup(
            price: $box['price'],
            atrPct: $atrPct,
            uptrend: $box['price'] >= $box['mid'],
            support: $box['low'],
            resistance: $box['high'],
            distanceToSupportPct: ($box['price'] - $box['low']) / $box['price'] * 100,
            roomToResistancePct: $targetPct,
            entry: $entry,
            reachPct: $this->reachPct($box),
            reasons: $reasons,
            checks: $checks,
            targetPct: $targetPct,
            stopPct: $stopPct,
            maxHoldMinutes: $hold,
        );
    }
}
