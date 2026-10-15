<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Trading\B3\B3Rules;
use PHPUnit\Framework\TestCase;

class B3RulesTest extends TestCase
{
    public function test_fractional_tickers_are_detected_and_trade_single_shares()
    {
        $this->assertTrue(B3Rules::isFractional('PETR4F'));
        $this->assertFalse(B3Rules::isFractional('PETR4'));
        $this->assertSame(1, B3Rules::lotSizeFor('VALE3F'));
        $this->assertSame(100, B3Rules::lotSizeFor('VALE3'));
    }

    public function test_standard_lot_rounds_down_and_fractional_caps_at_99()
    {
        $standard = new Asset(['symbol' => 'PETR4', 'lot_size' => '100', 'tick_size' => '0.01']);
        $fraction = new Asset(['symbol' => 'PETR4F', 'lot_size' => '1', 'tick_size' => '0.01']);
        $rules = new B3Rules;

        $this->assertSame(200.0, $rules->normalizeQuantity($standard, 250));
        $this->assertSame(0.0, $rules->normalizeQuantity($standard, 99));
        $this->assertSame(37.0, $rules->normalizeQuantity($fraction, 37.9));
        $this->assertSame(99.0, $rules->normalizeQuantity($fraction, 250));
    }

    public function test_prices_snap_to_the_tick()
    {
        $asset = new Asset(['symbol' => 'PETR4', 'lot_size' => '100', 'tick_size' => '0.01']);

        $this->assertEqualsWithDelta(38.13, (new B3Rules)->normalizePrice($asset, 38.1349), 1e-9);
    }
}
