<?php

namespace App\MarketData;

use App\Models\Asset;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fundamentals the Fundamentals agent reads. Refreshed by `market:sync-b3`
 * into the cache so scenarios never wait on the provider; stale data is
 * better than no data, but it expires after a week.
 */
class B3Fundamentals
{
    public function __construct(private readonly BrapiB3 $brapi) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Asset $asset): ?array
    {
        return Cache::get($this->key($asset));
    }

    public function refresh(Asset $asset): bool
    {
        try {
            $data = $this->brapi->fundamentals($asset->symbol);
        } catch (Throwable) {
            return false;
        }

        Cache::put($this->key($asset), $data + ['fetched_at' => now()->toIso8601String()], now()->addWeek());

        return true;
    }

    private function key(Asset $asset): string
    {
        return 'b3:fundamentals:'.$asset->symbol;
    }
}
