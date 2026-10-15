<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['portfolio_id', 'taken_at', 'equity', 'cash', 'exposure', 'drawdown'])]
class PortfolioSnapshot extends Model
{
    public $timestamps = false;

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

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
