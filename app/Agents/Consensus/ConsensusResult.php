<?php

namespace App\Agents\Consensus;

use App\Enums\OrderSide;

final readonly class ConsensusResult
{
    public function __construct(
        public bool $approved,
        public ?OrderSide $side,
        public float $score,
        public int $agreeing,
        public bool $vetoed,
        public string $reason,
        /** @var array<string, mixed>|null */
        public ?array $calculation = null,
    ) {}
}
