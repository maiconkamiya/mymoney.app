<?php

namespace App\Trading\Live;

use Illuminate\Support\Facades\Cache;

/**
 * Market sales whose fate the exchange did not tell (the request left, the
 * answer did not come back, and looking it up failed too). The position keeps
 * the sale's client id, so the next attempt asks the exchange for that id
 * before sending anything: the sale is never sent twice, and the portfolio
 * does not have to stop for a person to look (#198).
 */
class UnknownSales
{
    public static function remember(int $positionId, string $clientId): void
    {
        Cache::put(self::key($positionId), $clientId, now()->addDays(2));
    }

    /**
     * The client id of the position's sale still unknown, or null.
     */
    public static function for(int $positionId): ?string
    {
        $clientId = Cache::get(self::key($positionId));

        return is_string($clientId) && $clientId !== '' ? $clientId : null;
    }

    public static function forget(int $positionId): void
    {
        Cache::forget(self::key($positionId));
    }

    private static function key(int $positionId): string
    {
        return 'binance:unknown-sale:'.$positionId;
    }
}
