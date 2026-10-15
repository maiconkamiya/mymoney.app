<?php

namespace Tests\Feature;

use App\Enums\PortfolioMode;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use App\Trading\TradingHalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class PortfolioPagesTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    public function test_user_creates_a_paper_portfolio()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('portfolios.store'), [
            'name' => 'Consenso completo',
            'market' => 'crypto',
            'initial_cash' => 5000,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'auto_execute' => '0',
        ]);

        $portfolio = Portfolio::sole();
        $response->assertRedirect(route('portfolios.show', $portfolio));
        $this->assertSame(PortfolioMode::Paper, $portfolio->mode);
        $this->assertSame('paper', $portfolio->broker);
        $this->assertEquals(5000, $portfolio->cash);
        $this->assertFalse($portfolio->auto_execute);
    }

    public function test_index_lists_only_the_users_portfolios()
    {
        $user = User::factory()->create();
        Portfolio::factory()->for($user)->create(['name' => 'Minha']);
        Portfolio::factory()->create(['name' => 'De outra pessoa']);

        $this->actingAs($user)->get(route('portfolios.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolios/Index')
                ->has('portfolios', 1)
                ->where('portfolios.0.name', 'Minha')
                ->where('halted', false));
    }

    public function test_other_users_cannot_open_a_portfolio()
    {
        $portfolio = Portfolio::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('portfolios.show', $portfolio))
            ->assertForbidden();
    }

    public function test_confirming_an_approved_signal_executes_it()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        $signal = Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $asset->id,
            'stage' => SignalStage::Approved,
            'side' => 'buy',
            'stop_price' => 95,
        ]);

        $this->actingAs($user)->post(route('signals.confirm', $signal))->assertRedirect();

        $this->assertSame(SignalStage::Executed, $signal->fresh()->stage);
        $this->assertSame("user:{$user->id}", $signal->fresh()->decided_by);
        $this->assertCount(1, $portfolio->openPositions()->get());

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolios/Show')
                ->has('positions', 1)
                ->where('signals.0.stage', 'executed'));
    }

    public function test_an_expired_signal_cannot_be_confirmed()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        $signal = Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Approved, 'side' => 'buy', 'stop_price' => 95]);

        $this->travel(config('trading.signal_ttl_minutes') + 1)->minutes();
        $this->quote($asset, 100);

        $this->actingAs($user)->post(route('signals.confirm', $signal))->assertStatus(422);

        $this->assertSame(SignalStage::Rejected, $signal->fresh()->stage);
        $this->assertStringContainsString('Expirado', $signal->fresh()->decision_reason);
        $this->assertCount(0, $portfolio->openPositions()->get());
    }

    public function test_discarding_a_signal_records_who_decided()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $signal = Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => Asset::factory()->create()->id, 'stage' => SignalStage::Approved]);

        $this->actingAs($user)->post(route('signals.reject', $signal))->assertRedirect();

        $this->assertSame(SignalStage::Rejected, $signal->fresh()->stage);
        $this->assertSame('Descartado manualmente.', $signal->fresh()->decision_reason);
    }

    public function test_kill_switch_can_be_toggled_from_the_panel()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('trading.halt'))->assertRedirect();
        $this->assertTrue(app(TradingHalt::class)->isHalted());

        $this->actingAs($user)->delete(route('trading.resume'))->assertRedirect();
        $this->assertFalse(app(TradingHalt::class)->isHalted());
    }
}
