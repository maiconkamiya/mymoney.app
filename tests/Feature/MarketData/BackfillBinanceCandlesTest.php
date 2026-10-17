<?php

namespace Tests\Feature\MarketData;

use App\Models\Asset;
use App\Models\Candle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillBinanceCandlesTest extends TestCase
{
    use RefreshDatabase;

    private function kline(int $ms, float $close): array
    {
        return [$ms, (string) $close, (string) $close, (string) $close, (string) $close, '2.5'];
    }

    public function test_downloads_pages_of_klines_and_does_not_duplicate_on_rerun()
    {
        $this->travelTo(now()->startOfMinute());
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $start = now()->subDay()->startOfMinute();

        Http::fake(function ($request) use ($start) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $from = (int) $query['startTime'];

            // Dá três candles na primeira página e um na segunda; depois, vazio.
            if ($from <= $start->getTimestampMs()) {
                return Http::response([
                    $this->kline($start->getTimestampMs(), 100),
                    $this->kline($start->copy()->addMinute()->getTimestampMs(), 101),
                    $this->kline($start->copy()->addMinutes(2)->getTimestampMs(), 102),
                ]);
            }

            if ($from === $start->copy()->addMinutes(3)->getTimestampMs()) {
                return Http::response([$this->kline($from, 103)]);
            }

            return Http::response([]);
        });

        $this->artisan('market:backfill-binance', ['--days' => 1])->assertSuccessful();
        $this->artisan('market:backfill-binance', ['--days' => 1])->assertSuccessful();

        $this->assertSame(4, Candle::where('asset_id', $asset->id)->count());
        $this->assertSame('103.00000000', Candle::orderByDesc('open_at')->first()->close);
    }

    public function test_fails_when_there_is_no_active_crypto_asset()
    {
        $this->artisan('market:backfill-binance')->assertFailed();
    }

    public function test_reports_exchange_errors()
    {
        Asset::factory()->create(['symbol' => 'ETHUSDT']);
        Http::fake(['*' => Http::response('blocked', 451)]);

        $this->artisan('market:backfill-binance')->assertFailed();
    }
}
