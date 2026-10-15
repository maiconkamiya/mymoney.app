<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'qty', 'price', 'fee', 'executed_at'])]
class Fill extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'price' => 'float',
            'fee' => 'float',
            'executed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
