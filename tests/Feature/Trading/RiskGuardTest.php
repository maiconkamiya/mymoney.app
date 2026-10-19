<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Trading\Data\OrderRequest;
use App\Trading\Risk\RiskGuard;
use App\Trading\TradingHalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class RiskGuardTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private Asset $asset;

    private Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($this->asset, 100);
        $this->portfolio = Portfolio::factory()->create(['initial_cash' => 10_000, 'cash' => 10_000]);
    }

    private function buy(float $qty, ?float $stop = 95): OrderRequest
    {
        return new OrderRequest($this->asset, OrderSide::Buy, $qty, stopPrice: $stop);
    }

    public function test_order_is_trimmed_to_the_per_order_limit()
    {
        $decision = app(RiskGuard::class)->check($this->portfolio, $this->buy(20));

        $this->assertTrue($decision->allowed);
        $this->assertEqualsWithDelta(5, $decision->order->qty, 1e-9); // 5% of 10 000 at 100
    }

    public function test_buy_without_stop_is_blocked()
    {
        $decision = app(RiskGuard::class)->check($this->portfolio, $this->buy(1, stop: null));

        $this->assertFalse($decision->allowed);
    }

    public function test_kill_switch_blocks_everything()
    {
        app(TradingHalt::class)->halt('test');

        $decision = app(RiskGuard::class)->check($this->portfolio, $this->buy(1));

        $this->assertFalse($decision->allowed);
        $this->assertSame(['Parada geral acionada.'], $decision->reasons);
    }

    public function test_reconciler_halt_spares_simulated_portfolios()
    {
        $halt = app(TradingHalt::class);
        $live = Portfolio::factory()->create(['mode' => PortfolioMode::Live, 'initial_cash' => 10_000, 'cash' => 10_000]);
        $halt->halt('reconciler', 'Divergência.', liveOnly: true);

        $this->assertTrue($halt->isHalted());
        $this->assertTrue($halt->isLiveOnly());
        $this->assertTrue(app(RiskGuard::class)->check($this->portfolio, $this->buy(1))->allowed);
        $this->assertContains('Parada geral acionada.', app(RiskGuard::class)->check($live, $this->buy(1))->reasons);

        // The manual halt blocks every portfolio again.
        $halt->halt('user:1');
        $this->assertFalse($halt->isLiveOnly());
        $this->assertSame(['Parada geral acionada.'], app(RiskGuard::class)->check($this->portfolio, $this->buy(1))->reasons);
    }

    public function test_reconciler_halt_never_narrows_a_manual_halt()
    {
        $halt = app(TradingHalt::class);
        $halt->halt('user:1');
        $halt->halt('reconciler', 'Divergência.', liveOnly: true);

        $this->assertFalse($halt->isLiveOnly());
        $this->assertFalse(app(RiskGuard::class)->check($this->portfolio, $this->buy(1))->allowed);
    }

    public function test_paused_portfolio_cannot_trade()
    {
        $this->portfolio->update(['status' => PortfolioStatus::Paused]);

        $this->assertFalse(app(RiskGuard::class)->check($this->portfolio, $this->buy(1))->allowed);
    }

    public function test_stale_price_blocks_orders()
    {
        $this->travel(5)->minutes();

        $this->assertFalse(app(RiskGuard::class)->check($this->portfolio->fresh(), $this->buy(1))->allowed);
    }

    public function test_daily_loss_limit_blocks_new_buys()
    {
        $this->portfolio->snapshots()->create(['taken_at' => now()->startOfDay()->addMinute(), 'equity' => 10_000, 'cash' => 10_000, 'exposure' => 0]);
        $this->portfolio->update(['cash' => 9_700]);

        $decision = app(RiskGuard::class)->check($this->portfolio, $this->buy(1));

        $this->assertFalse($decision->allowed);
        $this->assertSame(['Perda máxima do dia atingida.'], $decision->reasons);
    }

    public function test_short_selling_is_blocked()
    {
        $decision = app(RiskGuard::class)->check($this->portfolio, new OrderRequest($this->asset, OrderSide::Sell, 1));

        $this->assertFalse($decision->allowed);
    }
}
