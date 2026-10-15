<?php

namespace Database\Factories;

use App\Enums\Market;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'symbol' => strtoupper(fake()->unique()->lexify('???')).'USDT',
            'market' => Market::Crypto,
            'quote_currency' => 'USDT',
            'lot_size' => '0.00001',
            'tick_size' => '0.01',
            'active' => true,
        ];
    }

    public function benchmark(string $symbol = 'IBOV'): static
    {
        return $this->b3($symbol)->state(fn () => ['is_benchmark' => true]);
    }

    public function b3(string $symbol = 'PETR4'): static
    {
        return $this->state(fn () => [
            'symbol' => $symbol,
            'market' => Market::B3,
            'quote_currency' => 'BRL',
            'lot_size' => 100,
            'tick_size' => '0.01',
        ]);
    }
}
