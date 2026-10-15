<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\PortfolioStatus;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Trading\OrderExecutor;
use App\Trading\PositionExits;
use App\Trading\TradingHalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class PositionExitsTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    /**
     * @return array{Portfolio, Asset, Signal}
     */
    private function openPosition(array $portfolio = []): array
    {
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        $portfolio = Portfolio::factory()->create(['market' => $asset->market, ...$portfolio]);

        $signal = $portfolio->signals()->create([
            'asset_id' => $asset->id,
            'stage' => SignalStage::Confirmed,
            'side' => OrderSide::Buy,
            'stop_price' => 95,
            'target_price' => 110,
        ]);

        $order = app(OrderExecutor::class)->execute($signal, 'test');
        $this->assertSame(OrderStatus::Filled, $order->status);

        return [$portfolio->fresh(), $asset, $signal->fresh()];
    }

    public function test_position_keeps_stop_target_and_the_signal_that_opened_it()
    {
        [$portfolio, , $signal] = $this->openPosition();

        $position = $portfolio->openPositions->sole();

        $this->assertSame($signal->id, $position->signal_id);
        $this->assertSame(95.0, $position->stop_price);
        $this->assertSame(110.0, $position->target_price);
    }

    public function test_nothing_happens_while_price_stays_between_stop_and_target()
    {
        [$portfolio, $asset] = $this->openPosition();
        $this->quote($asset, 102, now()->addMinute());

        $this->assertSame(0, app(PositionExits::class)->checkStopsAndTargets());
        $this->assertCount(1, $portfolio->openPositions);
    }

    public function test_a_blocked_exit_is_logged_once_per_hour_not_once_per_minute()
    {
        [, $asset] = $this->openPosition();
        $this->quote($asset, 94, now()->addMinute());
        Carbon::setTestNow(now()->addMinute());
        app(TradingHalt::class)->halt('teste');

        foreach (range(1, 3) as $_) {
            $this->assertSame(0, app(PositionExits::class)->checkStopsAndTargets());
        }

        $this->assertSame(1, AuditLog::where('action', 'position.close_blocked')->count());

        Carbon::setTestNow(now()->addHour()->addMinute());
        $this->quote($asset, 94, now());
        app(PositionExits::class)->checkStopsAndTargets();

        $this->assertSame(2, AuditLog::where('action', 'position.close_blocked')->count());
    }

    public function test_stop_sells_the_position_and_closes_the_signal()
    {
        [$portfolio, $asset, $signal] = $this->openPosition();
        $this->quote($asset, 94, now()->addMinute());
        Carbon::setTestNow(now()->addMinute());

        $this->assertSame(1, app(PositionExits::class)->checkStopsAndTargets());

        $position = $portfolio->positions()->sole();
        $this->assertNotNull($position->closed_at);
        $this->assertSame('stop', $position->exit_reason);
        $this->assertLessThan(0, $position->realized_pnl);

        $exit = $portfolio->orders()->where('side', OrderSide::Sell)->sole();
        $this->assertSame('stop', $exit->reason);
        $this->assertSame($signal->id, $exit->signal_id);
        $this->assertSame(SignalStage::Closed, $signal->fresh()->stage);
    }

    public function test_target_sells_with_profit()
    {
        [$portfolio, $asset] = $this->openPosition();
        $this->quote($asset, 111, now()->addMinute());
        Carbon::setTestNow(now()->addMinute());

        $this->assertSame(1, app(PositionExits::class)->checkStopsAndTargets());

        $position = $portfolio->positions()->sole();
        $this->assertSame('target', $position->exit_reason);
        $this->assertGreaterThan(0, $position->realized_pnl);
    }

    public function test_expired_portfolio_is_liquidated_and_finished()
    {
        [$portfolio, $asset] = $this->openPosition();
        $this->quote($asset, 101, now()->addMinute());
        Carbon::setTestNow(now()->addMinute());
        $portfolio->update(['ends_at' => now()->subSecond()]);

        $this->assertSame(1, app(PositionExits::class)->finishExpired());

        $portfolio->refresh();
        $this->assertSame(PortfolioStatus::Finished, $portfolio->status);
        $this->assertCount(0, $portfolio->openPositions);
        $this->assertSame('period_end', $portfolio->positions()->sole()->exit_reason);
        $this->assertSame(1, $portfolio->snapshots()->count());
    }

    public function test_portfolio_stays_running_while_a_position_cannot_be_sold()
    {
        [$portfolio] = $this->openPosition();
        $portfolio->update(['ends_at' => now()->subSecond()]);
        $this->app->make(TradingHalt::class)->halt('teste');

        $this->assertSame(0, app(PositionExits::class)->finishExpired());

        $this->assertSame(PortfolioStatus::Running, $portfolio->fresh()->status);
        $this->assertCount(1, $portfolio->fresh()->openPositions);
    }
}
