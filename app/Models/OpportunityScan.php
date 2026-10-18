<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma varredura do explorador: quantos pares viu, quantos passaram nos filtros e o custo da IA.
 *
 * @property int $id
 * @property int $pairs_seen
 * @property int $pairs_passed
 * @property string|null $ai_model
 * @property float $ai_cost
 * @property string|null $error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, Opportunity> $opportunities
 */
#[Fillable(['pairs_seen', 'pairs_passed', 'ai_model', 'ai_cost', 'error'])]
class OpportunityScan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_cost' => 'float',
        ];
    }

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class)->orderBy('rank');
    }
}
