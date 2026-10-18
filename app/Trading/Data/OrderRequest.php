<?php

namespace App\Trading\Data;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Models\Asset;

final readonly class OrderRequest
{
    public function __construct(
        public Asset $asset,
        public OrderSide $side,
        public float $qty,
        public OrderType $type = OrderType::Market,
        public ?float $limitPrice = null,
        public ?float $stopPrice = null,
        public ?string $clientId = null,
        // The position a sell reduces: the risk guard caps the sale at that one, not at another of the same pair.
        public ?int $positionId = null,
    ) {}

    public function withQty(float $qty): self
    {
        return new self($this->asset, $this->side, $qty, $this->type, $this->limitPrice, $this->stopPrice, $this->clientId, $this->positionId);
    }
}
