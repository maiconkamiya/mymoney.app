<?php

namespace Tests\Unit;

use App\Agents\Chart\ChartAnalyzer;
use App\Agents\Chart\Indicators;
use App\Agents\Chart\Timeframes;
use PHPUnit\Framework\TestCase;

class ChartAnalyzerTest extends TestCase
{
    /** A multiple of 4 h, so every timeframe bucket starts on a clean boundary. */
    private const START = 1_700_006_400;

    /**
     * Turns 5-minute bars (o, h, l, c, v) into the 1-minute bars the analyzer reads.
     *
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float}>  $fiveMinute
     * @return list<array{t: int, o: float, h: float, l: float, c: float, v: float}>
     */
    private function oneMinute(array $fiveMinute): array
    {
        $bars = [];

        foreach ($fiveMinute as $i => [$o, $h, $l, $c, $v]) {
            $t = self::START + $i * 300;
            $shape = [[$o, $o, $o, $o], [$o, $h, $o, $h], [$h, $h, $l, $l], [$l, $c, $l, $c], [$c, $c, $c, $c]];

            foreach ($shape as $m => [$open, $high, $low, $close]) {
                $bars[] = ['t' => $t + $m * 60, 'o' => $open, 'h' => $high, 'l' => $low, 'c' => $close, 'v' => $v / 5];
            }
        }

        return $bars;
    }

    /**
     * 60 five-minute bars swinging between 100 and 110: three peaks (high 111) and three troughs (low 99).
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    private function range(): array
    {
        $bars = [];

        for ($i = 0; $i < 60; $i++) {
            $close = 105 + 5 * sin($i * 2 * M_PI / 20);
            $bars[] = [$close, $close + 1, $close - 1, $close, 100];
        }

        return $bars;
    }

    /**
     * @param  list<array{0: float, 1: float, 2: float, 3: float, 4: float}>  $fiveMinute
     * @return array<string, mixed>
     */
    private function fiveMinute(array $fiveMinute): array
    {
        $analysis = (new ChartAnalyzer)->analyze($this->oneMinute($fiveMinute), self::START + 100 * 86400);

        return $analysis['timeframes']['5m'];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function types(array $events): array
    {
        return array_column($events, 'type');
    }

    public function test_aggregates_one_minute_bars_into_larger_bars()
    {
        $bars = [];

        for ($i = 0; $i < 10; $i++) {
            $bars[] = ['t' => self::START + $i * 60, 'o' => 10 + $i, 'h' => 20 + $i, 'l' => 5 + $i, 'c' => 11 + $i, 'v' => 2];
        }

        $five = Timeframes::aggregate($bars, 5);

        $this->assertCount(2, $five);
        $this->assertSame(self::START, $five[0]['t']);
        $this->assertEquals([10.0, 24.0, 5.0, 15.0, 10.0], [$five[0]['o'], $five[0]['h'], $five[0]['l'], $five[0]['c'], $five[0]['v']]);
        $this->assertSame(20.0, (float) $five[1]['c']);
        $this->assertEquals(10.0, $five[1]['v']);
    }

    public function test_timeframes_without_enough_history_are_omitted_and_never_invented()
    {
        $analysis = (new ChartAnalyzer)->analyze($this->oneMinute($this->range()), self::START + 100 * 86400);

        $this->assertTrue($analysis['usable']);
        $this->assertSame(['5m'], array_keys($analysis['timeframes']));
        $this->assertSame(['15m', '1h', '4h', '1d'], array_keys($analysis['omitted']));
        $this->assertStringContainsString('histórico insuficiente', $analysis['omitted']['4h']);
    }

    public function test_too_few_candles_make_the_chart_unusable()
    {
        $analysis = (new ChartAnalyzer)->analyze(array_slice($this->oneMinute($this->range()), 0, 100), self::START + 86400);

        $this->assertFalse($analysis['usable']);
        $this->assertSame([], $analysis['timeframes']);
        $this->assertNull($analysis['alignment']);
        $this->assertNull($analysis['risk_reference']);
    }

    public function test_finds_support_and_resistance_with_their_touches()
    {
        $chart = $this->fiveMinute($this->range());

        $this->assertEqualsWithDelta(111.0, $chart['resistances'][0]['price'], 0.01);
        $this->assertSame(3, $chart['resistances'][0]['touches']);
        $this->assertEqualsWithDelta(99.0, $chart['supports'][0]['price'], 0.01);
        $this->assertGreaterThanOrEqual(2, $chart['supports'][0]['touches']);
    }

    public function test_rising_series_is_an_uptrend_with_ordered_emas()
    {
        $bars = [];

        for ($i = 0; $i < 60; $i++) {
            $close = 100 + $i * 0.5;
            $bars[] = [$close - 0.2, $close + 0.3, $close - 0.4, $close, 100];
        }

        $chart = $this->fiveMinute($bars);

        $this->assertSame('alta', $chart['trend']);
        $this->assertGreaterThan($chart['ema21'], $chart['ema9']);
        $this->assertGreaterThan($chart['ema50'], $chart['ema21']);
        $this->assertGreaterThan(70, $chart['rsi']);
    }

    public function test_close_above_resistance_with_volume_is_a_breakout()
    {
        $bars = array_merge($this->range(), [
            [103.1, 108.5, 103.0, 108.0, 500],
            [108.0, 113.0, 107.5, 112.5, 500],
            [112.5, 114.5, 112.0, 114.0, 500],
        ]);

        $chart = $this->fiveMinute($bars);
        $event = $chart['events'][0];

        $this->assertSame(['rompimento_alta'], $this->types($chart['events']));
        $this->assertEqualsWithDelta(111.0, $event['level'], 0.01);
        $this->assertTrue($event['volume_confirmed']);
    }

    public function test_breakout_that_returns_below_the_level_is_a_false_breakout()
    {
        $bars = array_merge($this->range(), [
            [103.1, 108.5, 103.0, 108.0, 100],
            [108.0, 113.0, 107.5, 112.5, 100],
            [112.5, 112.8, 109.0, 109.5, 100],
        ]);

        $this->assertSame(['falso_rompimento_alta'], $this->types($this->fiveMinute($bars)['events']));
    }

    public function test_close_below_support_is_a_downside_breakout()
    {
        $bars = array_merge($this->range(), [
            [103.1, 103.5, 100.0, 100.5, 500],
            [100.5, 100.8, 97.0, 97.5, 500],
            [97.5, 97.8, 95.0, 96.0, 500],
        ]);

        $this->assertSame(['rompimento_baixa'], $this->types($this->fiveMinute($bars)['events']));
    }

    public function test_price_stalling_under_resistance_is_a_resistance_test()
    {
        $bars = array_merge($this->range(), [
            [103.1, 108.5, 103.0, 108.0, 100],
            [108.0, 110.8, 107.5, 110.0, 100],
            [110.0, 110.9, 109.5, 110.2, 100],
        ]);

        $this->assertSame(['teste_resistencia'], $this->types($this->fiveMinute($bars)['events']));
    }

    public function test_reports_a_tight_consolidation_band()
    {
        $bars = [];

        for ($i = 0; $i < 60; $i++) {
            $close = 100 + ($i % 2 === 0 ? 0.3 : -0.3);
            $bars[] = [$close, $close + 0.2, $close - 0.2, $close, 100];
        }

        $range = $this->fiveMinute($bars)['consolidation'];

        $this->assertNotNull($range);
        $this->assertSame(20, $range['bars']);
        $this->assertLessThanOrEqual(1.0, $range['high'] - $range['low']);
    }

    public function test_indicators_return_null_without_enough_data()
    {
        $this->assertNull(Indicators::ema([1.0, 2.0], 9));
        $this->assertNull(Indicators::rsi([1.0, 2.0], 14));
        $this->assertNull(Indicators::atr([['h' => 2.0, 'l' => 1.0, 'c' => 1.5]], 14));
        $this->assertSame(100.0, Indicators::rsi(range(1, 20), 14));
    }
}
