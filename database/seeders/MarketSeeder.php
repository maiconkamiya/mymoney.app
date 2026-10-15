<?php

namespace Database\Seeders;

use App\Enums\Market;
use App\Models\Asset;
use App\Models\RiskProfile;
use Illuminate\Database\Seeder;

class MarketSeeder extends Seeder
{
    /**
     * Starter watchlist and the default risk profile from the spec.
     */
    public function run(): void
    {
        RiskProfile::firstOrCreate(['name' => 'Padrão']);

        $crypto = ['BTCUSDT' => '0.00001', 'ETHUSDT' => '0.0001', 'SOLUSDT' => '0.001'];

        foreach ($crypto as $symbol => $lot) {
            Asset::firstOrCreate(
                ['market' => Market::Crypto, 'symbol' => $symbol],
                ['quote_currency' => 'USDT', 'lot_size' => $lot, 'tick_size' => '0.01'],
            );
        }

        $b3 = [
            'PETR4' => 'Petróleo', 'VALE3' => 'Mineração', 'ITUB4' => 'Bancos', 'BBDC4' => 'Bancos', 'BBAS3' => 'Bancos',
            'ABEV3' => 'Bebidas', 'WEGE3' => 'Bens industriais', 'B3SA3' => 'Financeiro', 'RENT3' => 'Aluguel de carros', 'SUZB3' => 'Papel e celulose',
        ];

        foreach ($b3 as $symbol => $sector) {
            Asset::firstOrCreate(
                ['market' => Market::B3, 'symbol' => $symbol],
                ['quote_currency' => 'BRL', 'lot_size' => 100, 'tick_size' => '0.01', 'sector' => $sector],
            );
        }
    }
}
