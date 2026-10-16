<?php

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class PortfolioChartsTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    public function test_show_page_carries_charts_with_positions_first()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['market' => Market::Crypto]);
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT', 'market' => Market::Crypto, 'quote_currency' => 'USDT']);
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT', 'market' => Market::Crypto, 'quote_currency' => 'USDT']);
        Asset::factory()->create(['symbol' => 'PETR4', 'market' => Market::B3]);
        $this->quote($btc, 100, now()->subMinutes(2));
        $this->quote($btc, 105);
        Position::create([
            'portfolio_id' => $portfolio->id, 'asset_id' => $eth->id, 'qty' => 1, 'avg_price' => 50,
            'stop_price' => 45, 'opened_at' => now(),
        ]);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->has('charts.assets', 2)
                ->where('charts.market_open', true)
                ->where('charts.assets.0.symbol', 'ETHUSDT')
                ->where('charts.assets.0.position.stop_price', 45)
                ->where('charts.assets.1.symbol', 'BTCUSDT')
                ->has('charts.assets.1.series', 2)
                ->where('charts.assets.1.series.1.4', 105));
    }

    public function test_charts_endpoint_returns_json_for_the_owner_only()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['market' => Market::Crypto]);

        $this->actingAs($user)->getJson(route('portfolios.charts', $portfolio))
            ->assertOk()
            ->assertJsonStructure(['market_open', 'phase', 'assets']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('portfolios.charts', $portfolio))
            ->assertForbidden();
    }
}
