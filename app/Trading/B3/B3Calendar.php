<?php

namespace App\Trading\B3;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Days the B3 does not trade: weekends, national holidays, the movable
 * holidays tied to Easter, and the extra closings configured in
 * `trading.b3_extra_holidays` (Y-m-d).
 */
class B3Calendar
{
    /** @var array<int, array<string, string>> */
    private array $cache = [];

    public function isTradingDay(CarbonInterface $day): bool
    {
        return ! $day->isWeekend() && $this->holidayName($day) === null;
    }

    /**
     * Quarta-feira de Cinzas: the B3 reopens after Carnival and trades only from the afternoon.
     */
    public function isAshWednesday(CarbonInterface $day): bool
    {
        $easter = $this->easter($day->year);

        return $day->format('Y-m-d') === $easter->copy()->subDays(46)->format('Y-m-d');
    }

    public function holidayName(CarbonInterface $day): ?string
    {
        return $this->holidays($day->year)[$day->format('Y-m-d')] ?? null;
    }

    /**
     * @return array<string, string> date => name
     */
    public function holidays(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $easter = $this->easter($year);

        $days = [
            "$year-01-01" => 'Confraternização Universal',
            $easter->copy()->subDays(48)->format('Y-m-d') => 'Carnaval (segunda-feira)',
            $easter->copy()->subDays(47)->format('Y-m-d') => 'Carnaval (terça-feira)',
            $easter->copy()->subDays(2)->format('Y-m-d') => 'Sexta-feira Santa',
            "$year-04-21" => 'Tiradentes',
            "$year-05-01" => 'Dia do Trabalho',
            $easter->copy()->addDays(60)->format('Y-m-d') => 'Corpus Christi',
            "$year-09-07" => 'Independência do Brasil',
            "$year-10-12" => 'Nossa Senhora Aparecida',
            "$year-11-02" => 'Finados',
            "$year-11-15" => 'Proclamação da República',
            "$year-12-24" => 'Véspera de Natal',
            "$year-12-25" => 'Natal',
            "$year-12-31" => 'Véspera de Ano Novo',
        ];

        // Consciência Negra became a national holiday in 2024.
        if ($year >= 2024) {
            $days["$year-11-20"] = 'Consciência Negra';
        }

        foreach ((array) config('trading.b3_extra_holidays', []) as $extra) {
            if (str_starts_with((string) $extra, (string) $year)) {
                $days[(string) $extra] = 'Feriado extra (configuração)';
            }
        }

        return $this->cache[$year] = $days;
    }

    /**
     * Gregorian Easter Sunday (Meeus/Jones/Butcher), without the calendar extension.
     */
    private function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
