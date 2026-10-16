<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\Candle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncBinanceCandlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_candles_come_from_the_market_data_host()
    {
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        Http::fake(['data-api.binance.vision/*' => Http::response([
            [1792072800000, '65000.10', '65100.00', '64950.00', '65050.50', '12.5', 1792072859999],
        ])]);

        $this->artisan('market:sync-binance')->assertSuccessful();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://data-api.binance.vision/api/v3/klines')
            && $request['symbol'] === 'BTCUSDT'
            && $request['interval'] === '1m');
        $candle = Candle::where('asset_id', $asset->id)->firstOrFail();
        $this->assertEquals(65050.5, (float) $candle->close);
        $this->assertSame(1792072800, $candle->open_at->getTimestamp());
    }

    public function test_the_market_data_host_is_configurable()
    {
        config(['trading.binance.market_data_url' => 'https://api.binance.com']);
        Asset::factory()->create(['symbol' => 'ETHUSDT']);
        Http::fake(['api.binance.com/*' => Http::response([])]);

        $this->artisan('market:sync-binance')->assertSuccessful();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.binance.com/api/v3/klines'));
    }

    public function test_a_blocked_location_is_reported_per_asset()
    {
        Asset::factory()->create(['symbol' => 'SOLUSDT']);
        Http::fake(['*' => Http::response(['code' => 0, 'msg' => 'Service unavailable from a restricted location'], 451)]);

        $this->artisan('market:sync-binance')
            ->expectsOutputToContain('SOLUSDT')
            ->assertSuccessful();

        $this->assertSame(0, Candle::count());
    }
}
