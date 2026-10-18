<?php

namespace App\Trading;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * One engine at a time per portfolio: the real-time micro process, the
 * minute run and the exits job read the same positions and cycles, and two
 * of them acting together could sell or buy the same pair twice.
 */
class PortfolioLock
{
    /** Seconds the lock outlives a crashed holder. */
    private const TTL = 120;

    /**
     * Run `$callback` holding the portfolio's lock, waiting up to `$wait`
     * seconds for it. Returns false, without running, when another engine
     * kept it all that time.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|false
     */
    public static function run(int $portfolioId, callable $callback, int $wait = 0): mixed
    {
        $lock = Cache::lock('trading:portfolio:'.$portfolioId, self::TTL);

        if ($wait <= 0) {
            if (! $lock->get()) {
                return false;
            }
        } else {
            try {
                $lock->block($wait);
            } catch (LockTimeoutException) {
                return false;
            }
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
