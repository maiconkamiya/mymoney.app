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
    ) {}

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
        ];
    }
}
