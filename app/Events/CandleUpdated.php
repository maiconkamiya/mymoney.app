<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A 1-minute candle changed (or closed). Sent straight to the socket server:
 * a queue would add the very delay the live dashboard exists to remove.
 */
class CandleUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array{time: int, open: float, high: float, low: float, close: float, volume: float}  $candle
     */
    public function __construct(
        public string $symbol,
        public string $timeframe,
        public array $candle,
        public bool $closed,
        public int $eventAt,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("market.{$this->symbol}")];
    }

    public function broadcastAs(): string
    {
        return 'candle.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'candle' => $this->candle,
            'closed' => $this->closed,
            'event_at' => $this->eventAt,
        ];
    }
}
