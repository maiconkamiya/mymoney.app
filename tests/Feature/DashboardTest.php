<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Candle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_the_dashboard_lists_crypto_assets_with_their_latest_candles_and_collector_status()
    {
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        Asset::factory()->b3()->create();
        Asset::factory()->create(['symbol' => 'OFFUSDT', 'active' => false]);

        foreach ([0, 1, 2] as $minute) {
            Candle::create([
                'asset_id' => $btc->id,
                'timeframe' => '1m',
                'open_at' => '2026-10-15 12:0'.$minute.':00',
                'open' => 100 + $minute, 'high' => 110, 'low' => 90, 'close' => 101 + $minute, 'volume' => 1,
            ]);
        }

        config(['market.collector.candles_on_load' => 2]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('assets', 1)
                ->where('assets.0.symbol', 'BTCUSDT')
                ->has('assets.0.candles', 2)
                ->where('assets.0.candles.1.close', 103)
                ->where('assets.0.candles.0.time', Carbon::parse('2026-10-15 12:01:00')->getTimestamp())
                ->where('collector.connected', false)
                ->where('collector.stalled', true));
    }
}
