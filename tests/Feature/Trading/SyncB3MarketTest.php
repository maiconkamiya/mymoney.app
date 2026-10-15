<?php

namespace Tests\Feature\Trading;

use App\MarketData\B3Fundamentals;
use App\Models\Asset;
use App\Models\Candle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncB3MarketTest extends TestCase
{
    use RefreshDatabase;

    public function test_candles_are_ingested_during_the_session_and_skip_empty_minutes()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00', 'America/Sao_Paulo'));
        $asset = Asset::factory()->b3()->create();
        Http::fake(['brapi.dev/*' => Http::response(['results' => [['historicalDataPrice' => [
            ['date' => 1792072800, 'open' => 38.0, 'high' => 38.2, 'low' => 37.9, 'close' => 38.1, 'volume' => 1200],
            ['date' => 1792072860, 'open' => null, 'high' => null, 'low' => null, 'close' => null, 'volume' => 0],
        ]]]])]);

        $this->artisan('market:sync-b3')->assertSuccessful();
        $this->artisan('market:sync-b3')->assertSuccessful(); // idempotent

        $this->assertSame(1, Candle::where('asset_id', $asset->id)->count());
        $this->assertEquals(38.1, (float) Candle::first()->close);
    }

    public function test_nothing_is_fetched_when_the_market_is_closed()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-17 11:00', 'America/Sao_Paulo')); // Saturday
        Asset::factory()->b3()->create();
        Http::fake();

        $this->artisan('market:sync-b3')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_fundamentals_are_cached_for_the_agents()
    {
        $asset = Asset::factory()->b3()->create();
        Http::fake(['brapi.dev/*' => Http::response(['results' => [[
            'priceEarnings' => 4.2,
            'earningsPerShare' => 9.1,
            'marketCap' => 480000000000,
            'defaultKeyStatistics' => ['priceToBook' => 1.3, 'dividendYield' => 0.12],
            'financialData' => ['returnOnEquity' => 0.25],
            'dividendsData' => ['cashDividends' => [['paymentDate' => '2026-08-20', 'rate' => 0.9, 'label' => 'DIVIDENDO']]],
        ]]])]);

        $this->artisan('market:sync-b3', ['--fundamentals' => true])->assertSuccessful();

        $data = app(B3Fundamentals::class)->for($asset);
        $this->assertSame(4.2, $data['pe']);
        $this->assertSame(0.12, $data['dividend_yield']);
        $this->assertSame(0.9, $data['dividends'][0]['amount']);
    }
}
