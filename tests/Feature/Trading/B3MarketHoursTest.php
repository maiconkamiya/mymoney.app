<?php

namespace Tests\Feature\Trading;

use App\Enums\Market;
use App\Trading\B3\B3Calendar;
use App\Trading\B3\MarketPhase;
use App\Trading\MarketHours;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class B3MarketHoursTest extends TestCase
{
    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'America/Sao_Paulo');
    }

    public function test_movable_holidays_follow_easter()
    {
        $holidays = (new B3Calendar)->holidays(2026); // Easter: 5 April

        $this->assertSame('Carnaval (segunda-feira)', $holidays['2026-02-16']);
        $this->assertSame('Carnaval (terça-feira)', $holidays['2026-02-17']);
        $this->assertSame('Sexta-feira Santa', $holidays['2026-04-03']);
        $this->assertSame('Corpus Christi', $holidays['2026-06-04']);
    }

    public function test_black_awareness_day_is_a_holiday_only_from_2024()
    {
        $calendar = new B3Calendar;

        $this->assertArrayNotHasKey('2023-11-20', $calendar->holidays(2023));
        $this->assertArrayHasKey('2024-11-20', $calendar->holidays(2024));
    }

    public function test_session_phases_on_a_trading_day()
    {
        $hours = app(MarketHours::class);

        $this->assertSame(MarketPhase::Closed, $hours->phase(Market::B3, $this->at('2026-10-15 09:30')));
        $this->assertSame(MarketPhase::OpeningAuction, $hours->phase(Market::B3, $this->at('2026-10-15 09:50')));
        $this->assertSame(MarketPhase::Continuous, $hours->phase(Market::B3, $this->at('2026-10-15 10:00')));
        $this->assertSame(MarketPhase::Continuous, $hours->phase(Market::B3, $this->at('2026-10-15 16:54')));
        $this->assertSame(MarketPhase::ClosingCall, $hours->phase(Market::B3, $this->at('2026-10-15 16:57')));
        $this->assertSame(MarketPhase::Closed, $hours->phase(Market::B3, $this->at('2026-10-15 17:00')));
    }

    public function test_holidays_and_weekends_are_closed_all_day()
    {
        $hours = app(MarketHours::class);

        $this->assertFalse($hours->isOpen(Market::B3, $this->at('2026-10-12 11:00'))); // Monday, Aparecida
        $this->assertFalse($hours->isOpen(Market::B3, $this->at('2026-04-03 11:00'))); // Good Friday
        $this->assertFalse($hours->isOpen(Market::B3, $this->at('2026-10-17 11:00'))); // Saturday
        $this->assertTrue($hours->isOpen(Market::B3, $this->at('2026-10-13 11:00')));
    }

    public function test_only_continuous_trading_counts_as_open_and_crypto_never_closes()
    {
        $hours = app(MarketHours::class);

        $this->assertFalse($hours->isOpen(Market::B3, $this->at('2026-10-15 09:50')));
        $this->assertTrue($hours->isOpen(Market::Crypto, $this->at('2026-12-25 03:00')));
    }

    public function test_extra_holidays_come_from_configuration()
    {
        config(['trading.b3_extra_holidays' => ['2026-10-13']]);

        $this->assertFalse(app(MarketHours::class)->isOpen(Market::B3, $this->at('2026-10-13 11:00')));
    }
}
