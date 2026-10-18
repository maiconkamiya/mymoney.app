<?php

namespace App\Trading\Micro;

use App\Enums\MicroRule;

/**
 * One door to both entry rules, so the engine, the card and the backtest read
 * the chart and size the exits the same way.
 */
class MicroRules
{
    public function __construct(
        private readonly MicroAnalysis $scalp,
        private readonly MicroTrendAnalysis $trend,
        private readonly MicroCosts $costs,
    ) {}

    /** Minutes of 1-minute candles the rule reads. */
    public function lookbackMinutes(MicroRule $rule): int
    {
        return match ($rule) {
            MicroRule::Scalp => (int) config('trading.micro.lookback_minutes'),
            MicroRule::TrendPullback => (int) config('trading.micro.trend.lookback_minutes'),
        };
    }

    /**
     * @param  list<array{high: float, low: float, close: float}>  $candles  oldest first
     * @param  float|null  $roundTripPct  cost of a buy plus a sell; null uses `trading.costs.crypto`
     */
    public function read(MicroRule $rule, array $candles, float $grossMarginPct, ?float $roundTripPct = null): ?MicroSetup
    {
        return match ($rule) {
            MicroRule::Scalp => $this->scalp->read($candles, $grossMarginPct),
            MicroRule::TrendPullback => $this->trend->read($candles, $roundTripPct ?? $this->costs->roundTripPct()),
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
}
