<?php

namespace App\Trading\Micro;

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
            MicroRule::Scalp => (int) config('trading.micro.lookback_minutes'),
            MicroRule::TrendPullback => (int) config('trading.micro.trend.lookback_minutes'),
            // A few spare minutes so a missing candle does not leave the 24 h reference out of the window.
            MicroRule::ScalpUptrend => max((int) config('trading.micro.uptrend.day_minutes'), (int) config('trading.micro.lookback_minutes')) + 5,
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
            MicroRule::Scalp => $this->scalp->read($candles, $grossMarginPct),
            MicroRule::TrendPullback => $this->trend->read($candles, $roundTripPct ?? $this->costs->roundTripPct()),
            MicroRule::ScalpUptrend => $this->scalpUptrend($candles, $grossMarginPct),
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
}
