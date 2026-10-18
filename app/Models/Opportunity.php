<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um par ranqueado numa varredura do explorador, com os números que o colocaram ali.
 *
 * @property int $id
 * @property int $opportunity_scan_id
 * @property string $symbol
 * @property string $base
 * @property int $rank
 * @property float $score
 * @property float $price
 * @property float $change_24h_pct
 * @property float $quote_volume
 * @property float $spread_pct
 * @property float|null $volume_accel
 * @property bool $breakout
 * @property float|null $extension_atr
 * @property list<string> $reasons
 * @property string|null $ai_note
 * @property CarbonImmutable|null $swarm_sent_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OpportunityScan $scan
 */
#[Fillable(['opportunity_scan_id', 'symbol', 'base', 'rank', 'score', 'price', 'change_24h_pct', 'quote_volume', 'spread_pct', 'volume_accel', 'breakout', 'extension_atr', 'reasons', 'ai_note', 'swarm_sent_at'])]
class Opportunity extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'price' => 'float',
            'change_24h_pct' => 'float',
            'quote_volume' => 'float',
            'spread_pct' => 'float',
            'volume_accel' => 'float',
            'breakout' => 'boolean',
            'extension_atr' => 'float',
            'reasons' => 'array',
            'swarm_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OpportunityScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(OpportunityScan::class, 'opportunity_scan_id');
    }
}
