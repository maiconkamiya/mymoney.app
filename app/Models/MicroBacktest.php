<?php

namespace App\Models;

use App\Enums\MicroRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One saved run of `micro:backtest` for a rule: the parameters, the numbers
 * of the fitting and validation parts, and whether validation passed.
 *
 * @property int $id
 * @property MicroRule $rule
 * @property array<string, mixed> $params
 * @property array<string, mixed> $summary
 * @property bool $passed
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['rule', 'params', 'summary', 'passed'])]
class MicroBacktest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => MicroRule::class,
            'params' => 'array',
            'summary' => 'array',
            'passed' => 'boolean',
        ];
    }

    public static function latestFor(MicroRule $rule): ?self
    {
        return self::query()->where('rule', $rule)->latest('id')->first();
    }
}
