<?php

namespace App\Trading\Micro;

use App\Agents\Chart\Indicators;
use App\Enums\MicroRule;

/**
 * One door to the entry rules, so the engine, the card and the backtest read
 * the chart and size the exits the same way.
 */
class MicroRules
{
    public function __construct(
        private readonly MicroAnalysis $scalp,
        private readonly MicroTrendAnalysis $trend,
        private readonly MicroCosts $costs,
        private readonly MicroBreakoutAnalysis $breakout,
    ) {}

    /** Minutes of 1-minute candles the rule reads. */
    public function lookbackMinutes(MicroRule $rule): int
    {
        return match ($rule) {
            MicroRule::Scalp, MicroRule::Scalp30 => (int) config('trading.micro.lookback_minutes') * $rule->timeframeMinutes(),
            MicroRule::TrendPullback => (int) config('trading.micro.trend.lookback_minutes'),
            // A few spare minutes so a missing candle does not leave the 24 h reference out of the window.
            MicroRule::ScalpUptrend, MicroRule::ScalpFiltered => max((int) config('trading.micro.uptrend.day_minutes'), (int) config('trading.micro.lookback_minutes')) + 5,
            MicroRule::Breakout, MicroRule::BreakoutPressure => $this->breakout->lookbackMinutes(),
        };
    }

    /**
     * @param  list<array{high: float, low: float, close: float, volume?: float}>  $candles  oldest first
     * @param  float|null  $roundTripPct  cost of a buy plus a sell; null uses `trading.costs.crypto`
     */
    public function read(MicroRule $rule, array $candles, float $grossMarginPct, ?float $roundTripPct = null): ?MicroSetup
    {
        return match ($rule) {
            MicroRule::Scalp, MicroRule::Scalp30 => $this->scalp->read($candles, $grossMarginPct, $rule->timeframeMinutes()),
            MicroRule::TrendPullback => $this->trend->read($candles, $roundTripPct ?? $this->costs->roundTripPct()),
            MicroRule::ScalpUptrend => $this->scalpUptrend($candles, $grossMarginPct),
            MicroRule::ScalpFiltered => $this->scalpFiltered($candles, $grossMarginPct),
            MicroRule::Breakout => $this->breakout->confirmed($candles, $roundTripPct ?? $this->costs->roundTripPct()),
            MicroRule::BreakoutPressure => $this->breakout->pressure($candles, $roundTripPct ?? $this->costs->roundTripPct()),
        };
    }

    /**
     * Target and stop in percent of the entry, and the time limit, for a setup.
     *
     * @return array{target_pct: float, stop_pct: float, max_hold_minutes: int}
     */
    public function exits(MicroSetup $setup, float $grossMarginPct): array
    {
        return [
            'target_pct' => $setup->targetPct ?? $grossMarginPct,
            'stop_pct' => $setup->stopPct ?? $grossMarginPct * (float) config('trading.micro.stop_ratio'),
            'max_hold_minutes' => $setup->maxHoldMinutes ?? (int) config('trading.micro.max_hold_minutes'),
        ];
    }

    /**
     * The current rule on its own window, plus a fifth condition: the last close
     * above the close of 24 h and of 4 h before (counted in candles back from the
     * last one, so nothing after it is seen). Same exits as the current rule.
     *
     * @param  list<array{high: float, low: float, close: float}>  $candles  oldest first
     */
    private function scalpUptrend(array $candles, float $grossMarginPct): ?MicroSetup
    {
        $day = (int) config('trading.micro.uptrend.day_minutes');
        $recent = (int) config('trading.micro.uptrend.recent_minutes');
        $count = count($candles);

        if ($count <= $day) {
            return null;
        }

        $setup = $this->scalp->read(array_slice($candles, -(int) config('trading.micro.lookback_minutes')), $grossMarginPct);

        if ($setup === null) {
            return null;
        }

        $price = (float) $candles[$count - 1]['close'];
        $dayBefore = (float) $candles[$count - 1 - $day]['close'];
        $recentBefore = (float) $candles[$count - 1 - $recent]['close'];
        $dayChange = $dayBefore > 0 ? ($price / $dayBefore - 1) * 100 : 0.0;
        $recentChange = $recentBefore > 0 ? ($price / $recentBefore - 1) * 100 : 0.0;
        $up = $dayChange > 0 && $recentChange > 0;
        $hours = sprintf('%d h e %d h', intdiv($day, 60), intdiv($recent, 60));

        $checks = [...$setup->checks, [
            'key' => 'uptrend',
            'label' => "Alta em {$hours}",
            'ok' => $up,
            'detail' => sprintf('%+.2f%% em %d h e %+.2f%% em %d h.', $dayChange, intdiv($day, 60), $recentChange, intdiv($recent, 60)),
        ]];

        $entry = $setup->entry && $up;

        if ($entry) {
            $reasons = [...$setup->reasons, sprintf('Alta de %+.2f%% em %d h e %+.2f%% em %d h.', $dayChange, intdiv($day, 60), $recentChange, intdiv($recent, 60))];
        } else {
            $reasons = $setup->entry ? [] : $setup->reasons;
            $up || $reasons[] = sprintf('Sem alta em %s (%+.2f%% em %d h, %+.2f%% em %d h).', $hours, $dayChange, intdiv($day, 60), $recentChange, intdiv($recent, 60));
        }

        return new MicroSetup(
            price: $setup->price,
            atrPct: $setup->atrPct,
            uptrend: $setup->uptrend,
            support: $setup->support,
            resistance: $setup->resistance,
            distanceToSupportPct: $setup->distanceToSupportPct,
            roomToResistancePct: $setup->roomToResistancePct,
            entry: $entry,
            reachPct: $setup->reachPct,
            reasons: $reasons,
            checks: $checks,
        );
    }

    /**
     * The 24 h and 4 h rule plus two filters (#204): the RSI of the last 1-minute
     * closes under `rsi_max`, so the buy does not chase a stretched move, and the
     * average volume of the last minutes above `volume_ratio` times the one of
     * the minutes before them. Same exits as the current rule.
     *
     * @param  list<array{high: float, low: float, close: float, volume?: float}>  $candles  oldest first
     */
    private function scalpFiltered(array $candles, float $grossMarginPct): ?MicroSetup
    {
        $setup = $this->scalpUptrend($candles, $grossMarginPct);
        $config = config('trading.micro.filtered');
        $recentMinutes = (int) $config['volume_recent_minutes'];
        $baseMinutes = (int) $config['volume_base_minutes'];

        if ($setup === null || count($candles) < $recentMinutes + $baseMinutes) {
            return null;
        }

        $rsiMax = (float) $config['rsi_max'];
        $window = array_slice($candles, -(int) config('trading.micro.lookback_minutes'));
        $rsi = Indicators::rsi(array_map(fn (array $c) => (float) $c['close'], $window), (int) $config['rsi_period']) ?? 50.0;
        $calm = $rsi < $rsiMax;

        $volume = fn (array $c) => (float) ($c['volume'] ?? 0);
        $recent = array_map($volume, array_slice($candles, -$recentMinutes));
        $base = array_map($volume, array_slice($candles, -($recentMinutes + $baseMinutes), $baseMinutes));
        $baseAverage = array_sum($base) / count($base);
        $ratio = $baseAverage > 0 ? array_sum($recent) / count($recent) / $baseAverage : 0.0;
        $loud = $ratio > (float) $config['volume_ratio'];

        $checks = [...$setup->checks, [
            'key' => 'rsi',
            'label' => 'RSI fora da sobrecompra',
            'ok' => $calm,
            'detail' => sprintf('RSI %d de 1 minuto em %.0f (máximo %.0f).', (int) $config['rsi_period'], $rsi, $rsiMax),
        ], [
            'key' => 'volume',
            'label' => 'Volume acima da média',
            'ok' => $loud,
            'detail' => sprintf('Volume dos últimos %d minutos %.2f vezes a média dos %d antes (precisa passar de %.2f).', $recentMinutes, $ratio, $baseMinutes, (float) $config['volume_ratio']),
        ]];

        $entry = $setup->entry && $calm && $loud;

        if ($entry) {
            $reasons = [...$setup->reasons, sprintf('RSI em %.0f e volume %.2f vezes a média.', $rsi, $ratio)];
        } else {
            $reasons = $setup->entry ? [] : $setup->reasons;
            $calm || $reasons[] = sprintf('RSI em %.0f: preço esticado (máximo %.0f).', $rsi, $rsiMax);
            $loud || $reasons[] = sprintf('Volume fraco: %.2f vezes a média dos %d minutos antes (precisa passar de %.2f).', $ratio, $baseMinutes, (float) $config['volume_ratio']);
        }

        return new MicroSetup(
            price: $setup->price,
            atrPct: $setup->atrPct,
            uptrend: $setup->uptrend,
            support: $setup->support,
            resistance: $setup->resistance,
            distanceToSupportPct: $setup->distanceToSupportPct,
            roomToResistancePct: $setup->roomToResistancePct,
            entry: $entry,
            reachPct: $setup->reachPct,
            reasons: $reasons,
            checks: $checks,
        );
    }
}
