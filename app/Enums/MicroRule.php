<?php

namespace App\Enums;

/**
 * The entry and exit rule of the micro operations, chosen per portfolio.
 */
enum MicroRule: string
{
    /** 1-minute reading: target = net margin + cost, stop the same size, 60 minutes. */
    case Scalp = 'scalp';

    /** 1 h and 15 min trend, entry on the pullback to the 15 min EMA21, exits sized by volatility, 4 hours. */
    case TrendPullback = 'trend_pullback';

    /** The 1-minute reading plus a fifth condition: price above where it was 24 h and 4 h ago. */
    case ScalpUptrend = 'scalp_uptrend';

    public function label(): string
    {
        return match ($this) {
            self::Scalp => 'Atual (1 minuto)',
            self::TrendPullback => 'Tendência com recuo (15 min e 1 h)',
            self::ScalpUptrend => 'Atual + alta em 24 h e 4 h',
        };
    }
}
