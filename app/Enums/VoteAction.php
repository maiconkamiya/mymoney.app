<?php

namespace App\Enums;

enum VoteAction: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case Hold = 'hold';

    /**
     * Direction of the vote: +1 buy, -1 sell, 0 hold.
     */
    public function direction(): int
    {
        return match ($this) {
            self::Buy => 1,
            self::Sell => -1,
            self::Hold => 0,
        };
    }
}
