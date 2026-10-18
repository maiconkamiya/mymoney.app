<?php

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
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

    public function test_target_line_uses_the_registered_sell_or_the_position_target()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['market' => Market::Crypto]);
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT', 'market' => Market::Crypto, 'quote_currency' => 'USDT']);
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT', 'market' => Market::Crypto, 'quote_currency' => 'USDT']);
        Position::create([
            'portfolio_id' => $portfolio->id, 'asset_id' => $btc->id, 'qty' => 1, 'avg_price' => 100,
            'stop_price' => 99, 'target_price' => 100.5, 'opened_at' => now(),
        ]);
        Position::create([
            'portfolio_id' => $portfolio->id, 'asset_id' => $eth->id, 'qty' => 1, 'avg_price' => 50,
            'stop_price' => null, 'target_price' => 50.25, 'opened_at' => now(),
        ]);
        Order::create([
            'portfolio_id' => $portfolio->id, 'asset_id' => $btc->id, 'broker' => 'paper', 'side' => OrderSide::Sell,
            'type' => OrderType::Limit, 'qty' => 1, 'price' => 100.6, 'status' => OrderStatus::Pending, 'fees' => 0,
        ]);

        $this->actingAs($user)->getJson(route('portfolios.charts', $portfolio))
            ->assertOk()
            ->assertJsonPath('assets.0.symbol', 'BTCUSDT')
            ->assertJsonPath('assets.0.position.target_price', 100.6)
            ->assertJsonPath('assets.0.position.sell_registered', true)
            ->assertJsonPath('assets.1.symbol', 'ETHUSDT')
            ->assertJsonPath('assets.1.position.target_price', 50.25)
            ->assertJsonPath('assets.1.position.sell_registered', false)
            ->assertJsonPath('assets.1.position.stop_price', null);
    }

    public function test_ceiling_line_comes_from_the_latest_recent_buy_signal()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['market' => Market::Crypto]);
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT', 'market' => Market::Crypto, 'quote_currency' => 'USDT']);
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT', 'market' => Market::Crypto, 'quote_currency' => 'USDT']);
        $signal = fn (Asset $asset, float $ceiling, ?OrderSide $side = OrderSide::Buy) => Signal::create([
            'portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Rejected, 'side' => $side,
            'scenario' => ['chart' => ['extension' => ['ceiling' => $ceiling]]],
        ]);

        $signal($btc, 101);
        $signal($btc, 102.5);
        $signal($btc, 90, OrderSide::Sell);
        $this->travel(-3)->hours();
        $signal($eth, 60);
        $this->travelBack();

        $this->actingAs($user)->getJson(route('portfolios.charts', $portfolio))
            ->assertOk()
            ->assertJsonPath('assets.0.symbol', 'BTCUSDT')
            ->assertJsonPath('assets.0.ceiling', 102.5)
            ->assertJsonPath('assets.1.symbol', 'ETHUSDT')
            ->assertJsonPath('assets.1.ceiling', null);
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
