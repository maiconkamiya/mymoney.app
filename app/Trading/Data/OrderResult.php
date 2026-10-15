<?php

namespace App\Trading\Data;

use App\Enums\OrderStatus;
use Carbon\CarbonInterface;

final readonly class OrderResult
{
    public function __construct(
        public OrderStatus $status,
        public ?string $externalId = null,
        public float $filledQty = 0,
        public ?float $avgPrice = null,
        public float $fees = 0,
        public ?CarbonInterface $filledAt = null,
        public ?string $rejectReason = null,
    ) {}

    public static function rejected(string $reason): self
    {
        return new self(OrderStatus::Rejected, rejectReason: $reason);
    }

    public function isFilled(): bool
    {
        return $this->status === OrderStatus::Filled || $this->status === OrderStatus::PartiallyFilled;
    }
}
