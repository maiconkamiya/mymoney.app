<?php

namespace App\Agents\Data;

use App\Enums\AgentRole;

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
     * @param  array<string, mixed>|null  $fundamentals
     * @param  list<array<string, mixed>>  $similarNews  Past news similar to the current ones, with the price move that followed.
     * @param  array<string, mixed>|null  $chart  Deterministic multi-timeframe chart reading, built for the Technical agent.
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
        public ?array $fundamentals = null,
        public array $similarNews = [],
        public ?array $chart = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * What one agent gets to read. The Technical agent works from the chart
     * reading and the latest candles only; the others never see the chart,
     * except Risk and the Skeptic, who get its "extension" (probable ceiling
     * and how stretched the rise is) to judge a late entry.
     *
     * @return array<string, mixed>
     */
    public function forRole(AgentRole $role): array
    {
        $data = $this->toArray();

        if ($role !== AgentRole::Technical) {
            unset($data['chart']);

            if (in_array($role, [AgentRole::Risk, AgentRole::Skeptic], true)) {
                $data['extension'] = $this->chart['extension'] ?? null;
            }

            return $data;
        }

        unset($data['news'], $data['similarNews'], $data['fundamentals'], $data['forecast']);
        $data['candles'] = array_slice($data['candles'], -20);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
