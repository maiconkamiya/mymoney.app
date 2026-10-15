<?php

namespace Tests\Feature\MarketData;

use App\Events\CandleUpdated;
use App\MarketData\BinanceStreamHandler;
use App\MarketData\CollectorStatus;
use App\Models\Asset;
use App\Models\Candle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BinanceStreamHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $symbol, float $close, bool $closed = false, int $openAtMs = 1_792_065_600_000, int $eventAtMs = 1_792_065_612_000): string
    {
        return json_encode([
            'stream' => strtolower($symbol).'@kline_1m',
            'data' => [
                'e' => 'kline',
                'E' => $eventAtMs,
                's' => $symbol,
                'k' => [
                    't' => $openAtMs, 'T' => $openAtMs + 59_999, 's' => $symbol, 'i' => '1m',
                    'o' => '100.00', 'c' => (string) $close, 'h' => '105.00', 'l' => '99.50', 'v' => '12.5', 'x' => $closed,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function handler(Asset ...$assets): BinanceStreamHandler
    {
        $handler = app(BinanceStreamHandler::class);
        $handler->track(collect($assets));

        return $handler;
    }

    public function test_streams_are_named_after_the_tracked_assets()
    {
        $handler = $this->handler(
            Asset::factory()->create(['symbol' => 'BTCUSDT']),
            Asset::factory()->create(['symbol' => 'ETHUSDT']),
        );

        $this->assertSame(['btcusdt@kline_1m', 'ethusdt@kline_1m'], $handler->streams());
    }

    public function test_a_kline_is_stored_as_a_candle_and_broadcast()
    {
        Event::fake([CandleUpdated::class]);
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);

        $candle = $this->handler($asset)->handle($this->message('BTCUSDT', 103.25, closed: true));

        $this->assertNotNull($candle);
        $stored = Candle::sole();
        $this->assertSame($asset->id, $stored->asset_id);
        $this->assertSame('1m', $stored->timeframe);
        $this->assertSame(1_792_065_600, $stored->open_at->getTimestamp());
        $this->assertEquals([100, 105, 99.5, 103.25, 12.5], [
            (float) $stored->open, (float) $stored->high, (float) $stored->low, (float) $stored->close, (float) $stored->volume,
        ]);

        Event::assertDispatched(CandleUpdated::class, fn (CandleUpdated $event) => $event->symbol === 'BTCUSDT'
            && $event->closed === true
            && $event->candle['time'] === 1_792_065_600
            && $event->candle['close'] === 103.25
            && $event->eventAt === 1_792_065_612);
    }

    public function test_updates_to_the_same_minute_keep_one_candle_and_a_new_minute_opens_another()
    {
        Event::fake([CandleUpdated::class]);
        $handler = $this->handler(Asset::factory()->create(['symbol' => 'BTCUSDT']));

        $handler->handle($this->message('BTCUSDT', 101));
        $handler->handle($this->message('BTCUSDT', 102));
        $this->assertSame(1, Candle::count());
        $this->assertEquals(102, (float) Candle::sole()->close);

        $handler->handle($this->message('BTCUSDT', 104, openAtMs: 1_792_065_660_000));
        $this->assertSame(2, Candle::count());
        Event::assertDispatchedTimes(CandleUpdated::class, 3);
    }

    public function test_the_collector_status_follows_the_stream()
    {
        Event::fake([CandleUpdated::class]);
        $this->travelTo('2026-10-15 12:00:20');
        $status = app(CollectorStatus::class);
        $status->markConnected();
        $this->assertTrue($status->snapshot()['stalled']);

        $this->handler(Asset::factory()->create(['symbol' => 'BTCUSDT']))
            ->handle($this->message('BTCUSDT', 103, eventAtMs: now()->subSeconds(3)->getTimestampMs()));

        $snapshot = $status->snapshot();
        $this->assertTrue($snapshot['connected']);
        $this->assertFalse($snapshot['stalled']);
        $this->assertSame(3, $snapshot['lag_seconds']);
        $this->assertSame(103.0, $snapshot['symbols']['BTCUSDT']['price']);

        $this->travelTo(now()->addSeconds(30));
        $this->assertTrue($status->snapshot()['stalled']);
        $this->assertSame(33, $status->snapshot()['lag_seconds']);

        $status->markDisconnected();
        $this->assertFalse($status->snapshot()['connected']);
    }

    public function test_unknown_symbols_other_timeframes_and_junk_are_ignored()
    {
        Event::fake([CandleUpdated::class]);
        $handler = $this->handler(Asset::factory()->create(['symbol' => 'BTCUSDT']));

        $this->assertNull($handler->handle($this->message('DOGEUSDT', 1)));
        $this->assertNull($handler->handle(str_replace('"i":"1m"', '"i":"5m"', $this->message('BTCUSDT', 1))));
        $this->assertNull($handler->handle('not json'));
        $this->assertNull($handler->handle('{"result":null,"id":1}'));

        $this->assertSame(0, Candle::count());
        Event::assertNothingDispatched();
    }
}
