<?php

namespace Tests\Feature;

use App\Enums\OrderSide;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_lists_running_portfolios_and_signals_waiting_for_confirmation()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $user->id, 'name' => 'Minha carteira']);
        $asset = Asset::factory()->create();
        Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Approved, 'side' => OrderSide::Buy, 'score' => 0.7]);
        Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Rejected]);
        Portfolio::factory()->create(['name' => 'De outra pessoa']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('halted', false)
                ->has('portfolios', 1)
                ->where('portfolios.0.name', 'Minha carteira')
                ->has('pending_signals', 1)
                ->where('pending_signals.0.side', 'buy'));
    }
}
