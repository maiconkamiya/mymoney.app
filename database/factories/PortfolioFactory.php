<?php

namespace Database\Factories;

use App\Enums\Market;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Models\Portfolio;
use App\Models\RiskProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Portfolio>
 */
class PortfolioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'risk_profile_id' => RiskProfile::factory(),
            'name' => 'Simulação '.fake()->word(),
            'market' => Market::Crypto,
            'mode' => PortfolioMode::Paper,
            'broker' => 'paper',
            'status' => PortfolioStatus::Running,
            'initial_cash' => 10_000,
            'cash' => 10_000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'auto_execute' => false,
        ];
    }
}
