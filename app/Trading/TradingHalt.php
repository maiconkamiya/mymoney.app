<?php

namespace App\Trading;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;

/**
 * The global kill switch. While halted, no order is sent for any portfolio.
 */
class TradingHalt
{
    private const KEY = 'trading:halted';

    public function isHalted(): bool
    {
        return Cache::get(self::KEY) !== null;
    }

    public function halt(string $actor, string $reason = ''): void
    {
        Cache::forever(self::KEY, ['by' => $actor, 'reason' => $reason, 'at' => now()->toIso8601String()]);
        AuditLog::record($actor, 'trading.halted', payload: ['reason' => $reason]);
    }

    public function resume(string $actor): void
    {
        Cache::forget(self::KEY);
        AuditLog::record($actor, 'trading.resumed');
    }
}
