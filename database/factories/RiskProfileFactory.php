<?php

namespace Database\Factories;

use App\Models\RiskProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskProfile>
 */
class RiskProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Padrão'];
    }
}
