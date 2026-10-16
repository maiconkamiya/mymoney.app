<?php

namespace Tests\Feature\Agents;

use App\Enums\SignalStage;
use App\Jobs\AnalyzeSignalJob;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\Portfolio;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PriceMoveTriggerTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        $this->travelTo(now()->startOfMinute()->addSeconds(30));
        config(['trading.move_trigger' => ['enabled' => true, 'pct' => 1.0, 'window_minutes' => 5, 'cooldown_minutes' => 15]]);

        $this->asset = Asset::factory()->create();
        Portfolio::factory()->create(['market' => $this->asset->market]);
    }

    private function candle(int $minutesAgo, float $close): Candle
    {
        return Candle::create([
            'asset_id' => $this->asset->id,
            'timeframe' => '1m',
            'open_at' => now()->startOfMinute()->subMinutes($minutesAgo),
            'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => 1,
        ]);
    }

    public function test_a_sharp_drop_opens_a_signal_and_calls_the_agents_at_once()
    {
        $this->candle(5, 100);
        $this->candle(0, 98.4);

        $signal = Signal::query()->firstOrFail();
        $this->assertSame(SignalStage::Scanned, $signal->stage);
        $this->assertStringContainsString('-1,60%', $signal->stage_history[0]['reason']);
        Queue::assertPushed(AnalyzeSignalJob::class, 1);
    }

    public function test_a_rise_triggers_too()
    {
        $this->candle(5, 100);
        $this->candle(0, 101.5);

        $this->assertSame(1, Signal::count());
    }

    public function test_a_small_move_does_nothing()
    {
        $this->candle(5, 100);
        $this->candle(0, 99.5);

        $this->assertSame(0, Signal::count());
        Queue::assertNothingPushed();
    }

    public function test_without_a_candle_from_the_window_nothing_happens()
    {
        $this->candle(0, 90);

        $this->assertSame(0, Signal::count());
    }

    public function test_it_can_be_turned_off()
    {
        config(['trading.move_trigger.enabled' => false]);
        $this->candle(5, 100);
        $this->candle(0, 90);

        $this->assertSame(0, Signal::count());
    }

    public function test_the_cooldown_holds_back_a_second_trigger()
    {
        $this->candle(5, 100);
        $this->candle(0, 98);
        Signal::query()->update(['stage' => SignalStage::Rejected]);
        Cache::forget('move-check:'.$this->asset->id);

        $this->travel(1)->minute();
        $this->candle(0, 97);

        $this->assertSame(1, Signal::count());
    }

    public function test_an_analysis_already_in_flight_is_not_duplicated()
    {
        $this->candle(5, 100);
        $this->candle(0, 98);
        Cache::flush();

        $this->travel(1)->minute();
        $this->candle(0, 97);

        $this->assertSame(1, Signal::count());
    }

    public function test_old_candles_written_by_a_backfill_do_not_trigger()
    {
        $this->candle(30, 100);
        $this->candle(25, 90);

        $this->assertSame(0, Signal::count());
    }

    public function test_the_scan_interval_is_configurable()
    {
        $this->assertSame(15, config('trading.scan_interval_minutes'));
    }
}
