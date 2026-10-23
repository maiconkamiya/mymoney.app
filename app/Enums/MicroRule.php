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

    /** The 1-minute reading on 30-minute bars: same conditions and exits, windows counted in bars (#218). */
    case Scalp30 = 'scalp_30m';

    /** The 1-minute reading with the reach of the week, plus the price above the week's mean and room below its high (#220). */
    case ScalpWeek = 'scalp_week';

    /**
     * Minutes of each bar the rule's chart reading groups the 1-minute candles into.
     */
    public function timeframeMinutes(): int
    {
        return match ($this) {
            self::Scalp30 => 30,
            default => 1,
        };
    }

    /**
     * The time limit of a cycle opened by this rule, when the card leaves it empty.
     */
    public function holdMinutes(): int
    {
        return match ($this) {
            self::TrendPullback => (int) config('trading.micro.trend.max_hold_minutes'),
            self::Breakout, self::BreakoutPressure => (int) config('trading.micro.breakout.max_hold_minutes'),
            default => (int) config('trading.micro.max_hold_minutes'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Scalp => 'Atual (1 minuto)',
            self::TrendPullback => 'Tendência com recuo (15 min e 1 h)',
            self::ScalpUptrend => 'Atual + alta em 24 h e 4 h',
            self::Breakout => 'Rompimento confirmado',
            self::BreakoutPressure => 'Pressão antes do rompimento',
            self::ScalpFiltered => 'Atual + alta + RSI e volume',
            self::Scalp30 => 'Atual (30 minutos)',
            self::ScalpWeek => 'Atual + semana (7 dias)',
        };
    }
}
