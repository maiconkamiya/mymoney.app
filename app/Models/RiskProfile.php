<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RiskProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property float $max_order_pct
 * @property float $max_position_pct
 * @property float $max_daily_loss_pct
 * @property float $max_drawdown_pct
 * @property int $max_open_positions
 * @property float $consensus_threshold
 * @property int $min_agreeing_voters
 * @property bool $require_stop
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'max_order_pct', 'max_position_pct', 'max_daily_loss_pct', 'max_drawdown_pct', 'max_open_positions', 'consensus_threshold', 'min_agreeing_voters', 'require_stop'])]
class RiskProfile extends Model
{
    /** @use HasFactory<RiskProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_order_pct' => 'float',
            'max_position_pct' => 'float',
            'max_daily_loss_pct' => 'float',
            'max_drawdown_pct' => 'float',
            'max_open_positions' => 'integer',
            'consensus_threshold' => 'float',
            'min_agreeing_voters' => 'integer',
            'require_stop' => 'boolean',
        ];
    }
}
