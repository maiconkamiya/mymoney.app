<?php

namespace App\Events;

use App\MarketData\CollectorStatus;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The stream worker connected or lost its connection.
 */
class CollectorStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('market.status')];
    }

    public function broadcastAs(): string
    {
        return 'collector.status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return app(CollectorStatus::class)->snapshot();
    }
}
