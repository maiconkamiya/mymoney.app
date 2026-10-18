<?php

namespace App\Agents\Consensus;

/**
 * Fixed rule against buying a rise too late, applied after the vote: the room
 * up to the probable ceiling must pay at least `min_reward_risk` times the
 * risk down to the stop, and the price may sit at most `max_ema21_atr` ATR
 * above the 21-period EMA. It only rejects buys; it never touches the risk
 * guard or the live limits, which still run on every order.
 */
class LateEntryCheck
{
    /**
     * @param  array<string, mixed>|null  $extension  The chart's "extension" reading.
     * @param  float|null  $stop  The signal's stop; without one below the price, the 1.5 ATR reference stop.
     * @return array{blocked: bool, reason: string|null, details: array<string, mixed>}|null Null when there is nothing to judge.
     */
    public function check(?array $extension, float $price, ?float $stop): ?array
    {
        if (! config('trading.late_entry.enabled') || $extension === null || ($extension['atr'] ?? 0) <= 0 || $price <= 0) {
            return null;
        }

        $minRewardRisk = (float) config('trading.late_entry.min_reward_risk');
        $maxEma21Atr = (float) config('trading.late_entry.max_ema21_atr');

        $ceiling = (float) $extension['ceiling'];
        $risk = $stop !== null && $stop < $price ? $price - $stop : 1.5 * (float) $extension['atr'];
        $rewardRisk = round(($ceiling - $price) / $risk, 2);
        $ema21 = $extension['ema21_distance_atr'] ?? null;

        $details = [
            'ceiling' => $ceiling,
            'ceiling_source' => $extension['ceiling_source'] ?? null,
            'room_pct' => $extension['room_pct'] ?? null,
            'stop' => $price - $risk,
            'reward_risk' => $rewardRisk,
            'min_reward_risk' => $minRewardRisk,
            'ema21_distance_atr' => $ema21,
            'max_ema21_atr' => $maxEma21Atr,
            'move_from_low_atr' => $extension['move_from_low_atr'] ?? null,
            'volume_fading' => $extension['volume_fading'] ?? null,
        ];

        $reasons = [];

        if ($rewardRisk < $minRewardRisk) {
            $reasons[] = sprintf('o ganho até o teto provável (%s) é %s vez o risco até o stop, mínimo %s', $this->number($ceiling), $this->number($rewardRisk), $this->number($minRewardRisk));
        }

        if ($ema21 !== null && $ema21 > $maxEma21Atr) {
            $reasons[] = sprintf('o preço está %s ATR acima da média de 21, máximo %s', $this->number((float) $ema21), $this->number($maxEma21Atr));
        }

        return [
            'blocked' => $reasons !== [],
            'reason' => $reasons === [] ? null : 'Entrada tardia: '.implode('; ', $reasons).'.',
            'details' => $details,
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, ',', ''), '0'), ',');
    }
}
