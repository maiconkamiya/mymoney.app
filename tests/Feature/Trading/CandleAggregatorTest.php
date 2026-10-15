<?php

namespace Tests\Feature\Trading;

use App\MarketData\CandleAggregator;
use App\Models\Asset;
use App\Models\Candle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandleAggregatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_trades_in_the_same_minute_build_one_candle()
    {
        $asset = Asset::factory()->create();
        $at = CarbonImmutable::parse('2026-10-15 12:00:05');
        $aggregator = app(CandleAggregator::class);

        $aggregator->ingestTrade($asset, 100, 1, $at);
        $aggregator->ingestTrade($asset, 103, 2, $at->addSeconds(20));
        $aggregator->ingestTrade($asset, 99, 1, $at->addSeconds(40));
        $aggregator->ingestTrade($asset, 101, 1, $at->addMinute());

        $candles = Candle::orderBy('open_at')->get();
        $this->assertCount(2, $candles);
        $this->assertEquals([100, 103, 99, 99, 4], [
            (float) $candles[0]->open, (float) $candles[0]->high, (float) $candles[0]->low, (float) $candles[0]->close, (float) $candles[0]->volume,
        ]);
    }
}
