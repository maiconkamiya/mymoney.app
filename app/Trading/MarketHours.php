<?php

namespace App\Trading;

use App\Enums\Market;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MarketHours
{
    /**
     * Whether continuous trading is open. Crypto never closes; B3 follows the
     * configured session on weekdays. Holidays and auctions are not modelled yet.
     */
    public function isOpen(Market $market, ?CarbonInterface $at = null): bool
    {
        if ($market === Market::Crypto) {
            return true;
        }

        $session = config('trading.b3_session');
        $local = Carbon::instance($at ?? now())->setTimezone($session['timezone']);

        if ($local->isWeekend()) {
            return false;
        }

        $time = $local->format('H:i');

        return $time >= $session['opens_at'] && $time < $session['closes_at'];
    }
}
