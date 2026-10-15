<?php

namespace App\Trading\Data;

use Carbon\CarbonInterface;

final readonly class Quote
{
    public function __construct(
        public float $price,
        public CarbonInterface $at,
    ) {}

    public function ageInSeconds(): int
    {
        return (int) abs(now()->diffInSeconds($this->at));
    }
}
