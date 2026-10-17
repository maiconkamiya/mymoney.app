<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened with real money in a portfolio: an order was sent or
 * failed, the guard refused an automatic one, or "Parar tudo" was triggered.
 * Goes only to the portfolio owner's private channel.
 *
 * Kinds: sent, failed, refused and panic.
 */
class LiveOrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $portfolioId,
        public readonly string $kind,
        public readonly string $message,
        public readonly array $payload = [],
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'live.order';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'portfolio_id' => $this->portfolioId,
            'kind' => $this->kind,
            'message' => $this->message,
            'at' => now()->toIso8601String(),
            ...$this->payload,
        ];
    }
}
