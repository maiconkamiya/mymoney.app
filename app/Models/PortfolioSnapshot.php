<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $portfolio_id
 * @property CarbonImmutable $taken_at
 * @property float $equity
 * @property float $cash
 * @property float $exposure
 * @property float $drawdown
 * @property-read Portfolio $portfolio
 */
#[Fillable(['portfolio_id', 'taken_at', 'equity', 'cash', 'exposure', 'drawdown'])]
class PortfolioSnapshot extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'equity' => 'float',
            'cash' => 'float',
            'exposure' => 'float',
            'drawdown' => 'float',
        ];
    }

    /** @return BelongsTo<Portfolio, $this> */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
