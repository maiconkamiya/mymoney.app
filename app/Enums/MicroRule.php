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

    /** A compressed 30-minute box, then a close above it with high volume (#181). */
    case Breakout = 'breakout';

    /** A compressed box with rising lows pressing a tested high: buys before the break (#181). */
    case BreakoutPressure = 'breakout_pressure';

    /** The 24 h and 4 h rule plus two filters: RSI not overbought and the last minutes' volume above the average (#204). */
    case ScalpFiltered = 'scalp_filtered';

    public function label(): string
    {
        return match ($this) {
            self::Scalp => 'Atual (1 minuto)',
            self::TrendPullback => 'Tendência com recuo (15 min e 1 h)',
            self::ScalpUptrend => 'Atual + alta em 24 h e 4 h',
            self::Breakout => 'Rompimento confirmado',
            self::BreakoutPressure => 'Pressão antes do rompimento',
            self::ScalpFiltered => 'Atual + alta + RSI e volume',
        };
    }
}
