<?php

namespace App\Agents;

use App\Agents\Data\Vote;
use App\Enums\AgentRole;
use App\Events\AgentActivityHappened;
use App\Models\Signal;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Announces what the agents do as they do it. Real-time is a view on the
 * trading, never part of it: if the socket server is down, the cycle goes on.
 */
class AgentActivityBroadcaster
{
    public function __construct(private readonly AgentActivity $activity) {}

    public function cycleStarted(Signal $signal): void
    {
        $this->send($signal, 'cycle.started', [
            'signal' => ['id' => $signal->id, 'symbol' => $signal->asset->symbol, 'stage' => $signal->stage->value],
        ]);
    }

    public function agentStarted(Signal $signal, AgentRole $role, int $round): void
    {
        $this->send($signal, 'agent.started', ['role' => $role->value, 'round' => $round]);
    }

    public function agentVoted(Signal $signal, Vote $vote, int $round): void
    {
        $this->send($signal, 'agent.voted', [
            'role' => $vote->agent->value,
            'round' => $round,
            'action' => $vote->action->value,
            'confidence' => $vote->confidence,
            'veto' => $vote->veto,
            'text' => $vote->reasons[0] ?? null,
        ]);
    }

    /**
     * The consensus is in: carries the full picture so every agent settles on its final stance.
     */
    public function consensusDecided(Signal $signal): void
    {
        $this->send($signal, 'consensus.decided', ['snapshot' => $this->activity->for($signal->portfolio)]);
    }

    public function orderStarted(Signal $signal): void
    {
        $this->send($signal, 'order.started', ['role' => AgentRole::Executor->value]);
    }

    public function orderFinished(Signal $signal): void
    {
        $this->send($signal, 'order.finished', ['snapshot' => $this->activity->for($signal->portfolio)]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(Signal $signal, string $type, array $payload): void
    {
        try {
            AgentActivityHappened::dispatch($signal->portfolio_id, $type, ['signal_id' => $signal->id, ...$payload]);
        } catch (Throwable $e) {
            Log::warning('Agent activity not broadcast', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
