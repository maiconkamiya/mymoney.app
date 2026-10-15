<?php

namespace App\Agents\Data;

use App\Enums\AgentRole;
use App\Enums\VoteAction;

final readonly class Vote
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $evidenceIds
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
    ) {}

    /**
     * A vote that counts as "hold", used when an agent fails or times out.
     */
    public static function abstain(AgentRole $agent, string $reason): self
    {
        return new self($agent, VoteAction::Hold, 0, reasons: [$reason]);
    }

    /**
     * Signed value between -1 and +1: buy is +confidence, sell is -confidence.
     */
    public function value(): float
    {
        return $this->action->direction() * $this->confidence;
    }

    /**
     * JSON schema the model's answer must follow.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
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
            ],
            'required' => ['action', 'confidence', 'veto', 'reasons', 'evidence_ids', 'stop', 'target'],
            'additionalProperties' => false,
        ];
    }
}
