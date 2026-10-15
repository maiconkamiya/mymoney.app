<?php

namespace App\Agents\Data;

/**
 * Everything the agents see about one opportunity. Built once per signal so
 * every agent judges the same facts.
 */
final readonly class Scenario
{
    /**
     * @param  list<array<string, mixed>>  $candles
     * @param  list<array<string, mixed>>  $news
     * @param  array<string, mixed>|null  $position
     * @param  array<string, mixed>|null  $forecast
     */
    public function __construct(
        public string $symbol,
        public string $market,
        public float $price,
        public string $asOf,
        public array $candles,
        public array $news,
        public ?array $position,
        public float $cash,
        public float $equity,
        public ?array $forecast = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
