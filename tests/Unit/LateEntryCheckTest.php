<?php

namespace Tests\Unit;

use App\Agents\Consensus\LateEntryCheck;
use Tests\TestCase;

class LateEntryCheckTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function extension(array $override = []): array
    {
        return $override + [
            'atr' => 1.0, 'ceiling' => 106.0, 'ceiling_source' => 'resistencia', 'room_pct' => 6.0,
            'ema21_distance_atr' => 1.0, 'move_from_low_atr' => 2.0, 'volume_fading' => false,
        ];
    }

    public function test_room_to_the_ceiling_that_pays_the_risk_passes()
    {
        $check = (new LateEntryCheck)->check($this->extension(), 100, 97);

        $this->assertFalse($check['blocked']);
        $this->assertNull($check['reason']);
        $this->assertSame(2.0, $check['details']['reward_risk']);
        $this->assertSame(97.0, $check['details']['stop']);
    }

    public function test_ceiling_too_close_for_the_risk_blocks_the_buy()
    {
        $check = (new LateEntryCheck)->check($this->extension(['ceiling' => 102.0]), 100, 97);

        $this->assertTrue($check['blocked']);
        $this->assertStringContainsString('Entrada tardia', $check['reason']);
        $this->assertStringContainsString('teto provável (102)', $check['reason']);
    }

    public function test_price_too_far_above_the_ema21_blocks_the_buy()
    {
        $check = (new LateEntryCheck)->check($this->extension(['ceiling' => 120.0, 'ema21_distance_atr' => 4.2]), 100, 97);

        $this->assertTrue($check['blocked']);
        $this->assertStringContainsString('4,2 ATR acima da média de 21', $check['reason']);
    }

    public function test_without_a_stop_below_the_price_the_reference_stop_of_1_5_atr_is_used()
    {
        $check = (new LateEntryCheck)->check($this->extension(['ceiling' => 102.0]), 100, null);

        $this->assertSame(98.5, $check['details']['stop']);
        $this->assertEqualsWithDelta(1.33, $check['details']['reward_risk'], 0.001);
        $this->assertTrue($check['blocked']);
    }

    public function test_limits_come_from_the_config_and_the_rule_can_be_turned_off()
    {
        config(['trading.late_entry.min_reward_risk' => 0.5]);
        $this->assertFalse((new LateEntryCheck)->check($this->extension(['ceiling' => 102.0]), 100, 97)['blocked']);

        config(['trading.late_entry.enabled' => false]);
        $this->assertNull((new LateEntryCheck)->check($this->extension(['ceiling' => 102.0]), 100, 97));
    }

    public function test_nothing_to_judge_without_the_chart_reading()
    {
        $this->assertNull((new LateEntryCheck)->check(null, 100, 97));
        $this->assertNull((new LateEntryCheck)->check($this->extension(['atr' => 0.0]), 100, 97));
    }
}
