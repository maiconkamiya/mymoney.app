<?php

namespace App\Trading;

use App\Enums\Market;
use App\Trading\B3\B3Calendar;
use App\Trading\B3\MarketPhase;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MarketHours
{
    public function __construct(private readonly B3Calendar $calendar = new B3Calendar) {}

    /**
     * Whether continuous trading is open, the only phase where orders fill.
     * Crypto never closes; B3 follows the configured session on trading days.
     */
    public function isOpen(Market $market, ?CarbonInterface $at = null): bool
    {
        return $this->phase($market, $at) === MarketPhase::Continuous;
    }

    /**
     * B3 phase at a given instant: opening auction, continuous trading,
     * closing call, or closed (night, weekends and holidays).
     */
    public function phase(Market $market, ?CarbonInterface $at = null): MarketPhase
    {
        if ($market === Market::Crypto) {
            return MarketPhase::Continuous;
        }

        $session = config('trading.b3_session');
        $local = Carbon::instance($at ?? now())->setTimezone($session['timezone']);

        if (! $this->calendar->isTradingDay($local)) {
            return MarketPhase::Closed;
        }

        $time = $local->format('H:i');

        if ($this->calendar->isAshWednesday($local)) {
            $session = array_merge($session, config('trading.b3_ash_wednesday_session'));
        }

        return match (true) {
            $time >= $session['opening_auction_at'] && $time < $session['opens_at'] => MarketPhase::OpeningAuction,
            $time >= $session['opens_at'] && $time < $session['closes_at'] => MarketPhase::Continuous,
            $time >= $session['closes_at'] && $time < $session['closing_call_until'] => MarketPhase::ClosingCall,
            default => MarketPhase::Closed,
        };
    }

    public function holidayName(CarbonInterface $day): ?string
    {
        return $this->calendar->holidayName($day);
    }
}
