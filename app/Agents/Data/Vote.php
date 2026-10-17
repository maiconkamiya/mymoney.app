<?php

namespace App\Agents\Data;

use App\Enums\AgentRole;
use App\Enums\VoteAction;

final readonly class Vote
{
    /** Expected moves the Technical agent can name for the chart. */
    public const CHART_SCENARIOS = ['alta', 'queda', 'rompimento_alta', 'rompimento_baixa', 'teste_resistencia', 'lateral'];

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $evidenceIds
     * @param  array<string, mixed>|null  $analysis  Chart reading from the Technical agent: scenario, supports, resistances.
     */
    public function __construct(
        public AgentRole $agent,
        public VoteAction $action,
        public float $confidence,
        public bool $veto = false,
        public array $reasons = [],
        public array $evidenceIds = [],
        public ?float $stop = null,
        public ?float $target = null,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0,
        public bool $abstained = false,
        public ?array $analysis = null,
    ) {}

    /**
     * The agent has nothing to base an opinion on (no data, or the model failed).
     * It is stored as a hold with zero confidence, but the consensus leaves it
     * out of the average and of the total weight.
     */
    public static function abstain(AgentRole $agent, string $reason): self
    {
        return new self($agent, VoteAction::Hold, 0, reasons: [$reason], abstained: true);
    }

    /**
     * Signed value between -1 and +1: buy is +confidence, sell is -confidence.
     */
    public function value(): float
    {
        return $this->abstained ? 0.0 : $this->action->direction() * $this->confidence;
    }

    /**
     * JSON schema the model's answer must follow.
     *
     * @return array<string, mixed>
     */
    public static function schema(?AgentRole $role = null): array
    {
        $chart = $role === AgentRole::Technical ? [
            'chart_scenario' => ['type' => 'string', 'enum' => self::CHART_SCENARIOS],
            'supports' => ['type' => 'array', 'items' => ['type' => 'number']],
            'resistances' => ['type' => 'array', 'items' => ['type' => 'number']],
            'timeframes' => ['type' => 'array', 'items' => ['type' => 'string']],
        ] : [];

        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['buy', 'sell', 'hold']],
                'confidence' => ['type' => 'number'],
                'veto' => ['type' => 'boolean'],
                'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                'evidence_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                'stop' => ['type' => ['number', 'null']],
                'target' => ['type' => ['number', 'null']],
                ...$chart,
            ],
            'required' => ['action', 'confidence', 'veto', 'reasons', 'evidence_ids', 'stop', 'target', ...array_keys($chart)],
            'additionalProperties' => false,
        ];
    }
}
