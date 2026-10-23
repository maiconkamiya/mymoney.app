<?php

namespace App\Trading\Micro;

/**
 * The reading of one asset's recent 1-minute candles.
 */
final readonly class MicroSetup
{
    /**
     * @param  list<string>  $reasons  why it passes, or what blocks it
     * @param  list<array{key: string, label: string, ok: bool, detail: string}>  $checks  each entry condition
     * @param  float|null  $targetPct  the rule's own target above the entry (null: the gross margin)
     * @param  float|null  $stopPct  the rule's own stop below the entry (null: the gross margin times the stop ratio)
     * @param  int|null  $maxHoldMinutes  the rule's own time limit (null: `trading.micro.max_hold_minutes`)
     * @param  float|null  $weekRangePct  average 1-minute range of the week (#220): the pairs in focus are ordered by it instead of `atrPct`
     */
    public function __construct(
        public float $price,
        public float $atrPct,
        public bool $uptrend,
        public float $support,
        public float $resistance,
        public float $distanceToSupportPct,
        public float $roomToResistancePct,
        public bool $entry,
        public float $reachPct,
        public array $reasons,
        public array $checks = [],
        public ?float $targetPct = null,
        public ?float $stopPct = null,
        public ?int $maxHoldMinutes = null,
        public ?float $weekRangePct = null,
    ) {}

    /**
     * How much the pair moves, to order the pairs in focus: the week's range when the rule reads it.
     */
    public function potentialPct(): float
    {
        return $this->weekRangePct ?? $this->atrPct;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'price' => $this->price,
            'atr_pct' => round($this->atrPct, 4),
            'uptrend' => $this->uptrend,
            'support' => $this->support,
            'resistance' => $this->resistance,
            'distance_to_support_pct' => round($this->distanceToSupportPct, 4),
            'room_to_resistance_pct' => round($this->roomToResistancePct, 4),
            'reach_pct' => round($this->reachPct, 4),
            'entry' => $this->entry,
            'reasons' => $this->reasons,
            'checks' => $this->checks,
            'target_pct' => $this->targetPct === null ? null : round($this->targetPct, 4),
            'stop_pct' => $this->stopPct === null ? null : round($this->stopPct, 4),
            'max_hold_minutes' => $this->maxHoldMinutes,
        ];
    }
}
