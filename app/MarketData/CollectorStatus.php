<?php

namespace App\MarketData;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the stream worker last saw, kept in the cache so the web process can
 * show the collector's health without talking to it.
 */
class CollectorStatus
{
    private const KEY = 'market:collector-status';

    public function markConnected(): void
    {
        $state = $this->read();
        $state['connected'] = true;
        $state['connected_at'] = now()->getTimestamp();

        $this->write($state);
    }

    public function markDisconnected(): void
    {
        $state = $this->read();
        $state['connected'] = false;

        $this->write($state);
    }

    public function recordTick(string $symbol, float $price, CarbonInterface $at): void
    {
        $state = $this->read();
        $state['last_message_at'] = $at->getTimestamp();
        $state['symbols'][$symbol] = ['price' => $price, 'at' => $at->getTimestamp()];

        $this->write($state);
    }

    /**
     * @return array{connected: bool, stalled: bool, connected_at: int|null, last_message_at: int|null, lag_seconds: int|null, symbols: array<string, array{price: float, at: int}>}
     */
    public function snapshot(): array
    {
        $state = $this->read();
        $lastMessageAt = $state['last_message_at'];
        $lag = $lastMessageAt === null ? null : max(0, (int) now()->diffInSeconds(Carbon::createFromTimestamp($lastMessageAt), true));

        return [
            'connected' => $state['connected'],
            'stalled' => ! $state['connected'] || $lag === null || $lag > (int) config('market.collector.stale_after_seconds'),
            'connected_at' => $state['connected_at'],
            'last_message_at' => $lastMessageAt,
            'lag_seconds' => $lag,
            'symbols' => $state['symbols'],
        ];
    }

    /**
     * @return array{connected: bool, connected_at: int|null, last_message_at: int|null, symbols: array<string, array{price: float, at: int}>}
     */
    private function read(): array
    {
        $state = Cache::get(self::KEY);

        return [
            'connected' => (bool) ($state['connected'] ?? false),
            'connected_at' => $state['connected_at'] ?? null,
            'last_message_at' => $state['last_message_at'] ?? null,
            'symbols' => $state['symbols'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function write(array $state): void
    {
        Cache::forever(self::KEY, $state);
    }
}
