<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something an agent really did in a portfolio's cycle, sent the moment it
 * happens so the agents card moves only when the swarm does.
 *
 * Types: cycle.started, agent.started, agent.voted, consensus.decided,
 * order.started and order.finished.
 */
class AgentActivityHappened implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $portfolioId,
        public readonly string $type,
        public readonly array $payload = [],
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('portfolios.'.$this->portfolioId.'.agents')];
    }

    public function broadcastAs(): string
    {
        return 'agent.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'at' => now()->toIso8601String(), ...$this->payload];
    }
}
