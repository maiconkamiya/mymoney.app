<?php

namespace App\Trading\Data;

final readonly class OcoResult
{
    public function __construct(
        public OrderResult $target,
        public OrderResult $stop,
        // The exchange's id of the list ("SYMBOL:orderListId"), or the simulated one.
        public ?string $group = null,
    ) {}

    public static function rejected(string $reason): self
    {
        return new self(OrderResult::rejected($reason), OrderResult::rejected($reason));
    }

    public function accepted(): bool
    {
        return $this->group !== null && $this->target->externalId !== null && $this->stop->externalId !== null;
    }
}
