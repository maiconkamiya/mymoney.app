<?php

namespace App\Models;

use App\Enums\AgentRole;
use App\Enums\Market;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agent', 'market', 'window', 'signals', 'hits', 'hit_rate', 'weight'])]
class AgentScore extends Model
{
    protected function casts(): array
    {
        return [
            'agent' => AgentRole::class,
            'market' => Market::class,
            'hit_rate' => 'float',
            'weight' => 'float',
        ];
    }
}
