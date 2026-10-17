<?php

namespace App\Trading\Live;

use RuntimeException;
use Throwable;

class LiveTradingException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        /** Binance's own error code (such as -2010), when the exchange answered. */
        public readonly ?int $binanceCode = null,
        /** True when the order may or may not have reached the exchange (timeout, 5xx). */
        public readonly bool $uncertain = false,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
