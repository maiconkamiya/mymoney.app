<?php

namespace App\Models;

use App\Enums\AgentRole;
use App\Enums\Market;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property AgentRole $agent
 * @property Market $market
 * @property int $window
 * @property int $signals
 * @property int $hits
 * @property float $hit_rate
 * @property float $weight
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['agent', 'market', 'window', 'signals', 'hits', 'hit_rate', 'weight'])]
class AgentScore extends Model
{
    /**
     * @return array<string, string>
     */
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
