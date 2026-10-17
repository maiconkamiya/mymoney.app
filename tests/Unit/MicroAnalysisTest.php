<?php

namespace Tests\Unit;

use App\Trading\Micro\MicroAnalysis;
use Tests\TestCase;

class MicroAnalysisTest extends TestCase
{
    /**
     * 95 flat minutes, one spike above, then a slow climb: uptrend near the support, room below the spike.
     *
     * @return list<array{high: float, low: float, close: float}>
     */
    private function climb(float $end = 100.15): array
    {
        $candles = [];

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + ($end - 100.0) * ($i - 94) / 25;
            $candles[] = ['high' => $close + 0.01 + ($i === 80 ? 0.9 : 0), 'low' => $close - 0.01, 'close' => $close];
        }

        return $candles;
    }

    public function test_enters_on_uptrend_near_support_with_room_to_resistance()
    {
        $setup = (new MicroAnalysis)->read($this->climb(), 0.5);

        $this->assertTrue($setup->uptrend);
        $this->assertTrue($setup->entry);
        $this->assertEqualsWithDelta(0.16, $setup->distanceToSupportPct, 0.02);
        $this->assertGreaterThan(0.5, $setup->roomToResistancePct);
    }

    public function test_no_entry_in_a_downtrend()
    {
        $candles = array_map(fn (array $c) => ['high' => 200 - $c['high'], 'low' => 200 - $c['low'], 'close' => 200 - $c['close']], $this->climb());
        $setup = (new MicroAnalysis)->read($candles, 0.5);

        $this->assertFalse($setup->uptrend);
        $this->assertFalse($setup->entry);
    }

    public function test_no_entry_when_the_resistance_is_too_close()
    {
        $candles = $this->climb();
        $candles[80]['high'] = 100.2;

        $setup = (new MicroAnalysis)->read($candles, 0.5);

        $this->assertFalse($setup->entry);
        $this->assertStringContainsString('Resistência perto', implode(' ', $setup->reasons));
    }

    public function test_no_entry_when_the_price_is_far_from_the_support()
    {
        $setup = (new MicroAnalysis)->read($this->climb(101.0), 0.5);

        $this->assertFalse($setup->entry);
        $this->assertStringContainsString('Longe do suporte', implode(' ', $setup->reasons));
    }

    public function test_too_few_candles_yield_no_reading()
    {
        $this->assertNull((new MicroAnalysis)->read(array_slice($this->climb(), 0, 20), 0.5));
    }
}
