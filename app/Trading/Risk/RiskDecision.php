<?php

namespace App\Trading\Risk;

use App\Trading\Data\OrderRequest;

final readonly class RiskDecision
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $allowed,
        public ?OrderRequest $order,
        public array $reasons = [],
    ) {}

    public static function block(string $reason): self
    {
        return new self(false, null, [$reason]);
    }

    public function summary(): string
    {
        return implode(' ', $this->reasons);
    }
}
