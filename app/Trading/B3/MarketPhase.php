<?php

namespace App\Trading\B3;

enum MarketPhase: string
{
    case Closed = 'closed';
    case OpeningAuction = 'opening_auction';
    case Continuous = 'continuous';
    case ClosingCall = 'closing_call';

    public function isAuction(): bool
    {
        return $this === self::OpeningAuction || $this === self::ClosingCall;
    }
}
