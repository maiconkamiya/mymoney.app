<?php

namespace Tests\Feature\Trading;

use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Brokers\BinanceBroker;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\LiveTradingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class BinanceAccountTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private Asset $btc;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->btc = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $this->quote($this->btc, 60_000);
    }

    private function credential(User $user, array $attributes = []): BrokerCredential
    {
        return BrokerCredential::create($attributes + [
            'user_id' => $user->id,
            'broker' => 'binance',
            'label' => 'teste',
            'api_key' => 'key-'.$user->id,
            'api_secret' => 'secret-'.$user->id,
            'can_withdraw' => false,
            'verified_at' => now(),
        ]);
    }

    /** @var list<array<string, string>> */
    private array $balances = [];

    private function fakeAccount(array $balances): void
    {
        $this->balances = $balances;
        Http::fake(['*/api/v3/account*' => fn () => Http::response(['balances' => $this->balances])]);
    }

    public function test_sync_mirrors_cash_and_tracked_assets_and_skips_dust()
    {
        $user = User::factory()->create();
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential($user));
        $this->fakeAccount([
            ['asset' => 'USDT', 'free' => '250.5', 'locked' => '0'],
            ['asset' => 'BTC', 'free' => '0.01', 'locked' => '0.005'],
            ['asset' => 'DOGE', 'free' => '10', 'locked' => '0'],
            ['asset' => 'BNB', 'free' => '0.000001', 'locked' => '0'],
        ]);

        $result = app(BinanceAccountSync::class)->sync($portfolio);

        $this->assertSame(PortfolioMode::Live, $portfolio->mode);
        $this->assertFalse($portfolio->auto_execute);
        $this->assertSame(1, $result['positions']);
        $this->assertEqualsCanonicalizing(['DOGE', 'BNB'], $result['ignored']);
        $this->assertEqualsWithDelta(250.5, $portfolio->fresh()->cash, 1e-6);
        $this->assertEqualsWithDelta(0.015, $portfolio->openPositions()->sole()->qty, 1e-9);
        $this->assertEqualsWithDelta(250.5 + 900, $portfolio->fresh()->initial_cash, 1e-6);
        Http::assertSent(fn ($request) => $request->hasHeader('X-MBX-APIKEY', 'key-'.$user->id) && str_contains($request->url(), 'signature='));
    }

    public function test_sync_closes_positions_that_left_the_account_and_keeps_cost_basis()
    {
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential(User::factory()->create()));
        $this->fakeAccount([['asset' => 'BTC', 'free' => '0.01', 'locked' => '0']]);
        app(BinanceAccountSync::class)->sync($portfolio);
        $portfolio->openPositions()->update(['avg_price' => 50_000]);

        $this->fakeAccount([['asset' => 'BTC', 'free' => '0.02', 'locked' => '0']]);
        app(BinanceAccountSync::class)->sync($portfolio);
        $this->assertEqualsWithDelta(50_000, $portfolio->openPositions()->sole()->avg_price, 1e-6);
        $this->assertEqualsWithDelta(0.02, $portfolio->openPositions()->sole()->qty, 1e-9);

        $this->fakeAccount([['asset' => 'USDT', 'free' => '10', 'locked' => '0']]);
        app(BinanceAccountSync::class)->sync($portfolio);
        $this->assertSame(0, $portfolio->openPositions()->count());
    }

    public function test_geoblocking_is_reported_clearly()
    {
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential(User::factory()->create()));
        Http::fake(['*' => Http::response(['code' => 0, 'msg' => 'Service unavailable from a restricted location'], 451)]);

        try {
            app(BinanceAccountSync::class)->sync($portfolio);
            $this->fail('Deveria falhar com 451.');
        } catch (LiveTradingException $e) {
            $this->assertStringContainsString('451', $e->getMessage());
            $this->assertStringContainsString('BINANCE_API_URL', $e->getMessage());
        }
    }

    public function test_base_url_follows_the_testnet_switch()
    {
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential(User::factory()->create()));
        config(['trading.binance.base_url' => 'https://testnet.binance.vision']);
        $this->fakeAccount([]);

        app(BinanceAccountSync::class)->sync($portfolio);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://testnet.binance.vision/api/v3/account'));
    }

    public function test_sync_refuses_a_credential_that_belongs_to_someone_else()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential($owner));
        $portfolio->update(['broker_credential_id' => $this->credential($other)->id]);

        $this->expectException(LiveTradingException::class);
        app(BinanceAccountSync::class)->sync($portfolio->fresh());
    }

    public function test_broker_balances_need_no_live_switch_but_refuse_foreign_keys()
    {
        $owner = User::factory()->create();
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential($owner));
        $this->fakeAccount([['asset' => 'USDT', 'free' => '5', 'locked' => '2']]);

        $this->assertSame(['USDT' => 5.0], app(BinanceBroker::class, ['portfolio' => $portfolio])->getBalances());

        $portfolio->update(['broker_credential_id' => $this->credential(User::factory()->create())->id]);
        $this->expectException(LiveTradingException::class);
        app(BinanceBroker::class, ['portfolio' => $portfolio->fresh()])->getBalances();
    }

    public function test_sync_command_uses_each_owners_own_key()
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        app(BinanceAccountSync::class)->portfolioFor($this->credential($a));
        app(BinanceAccountSync::class)->portfolioFor($this->credential($b));
        $this->fakeAccount([['asset' => 'USDT', 'free' => '1', 'locked' => '0']]);

        $this->artisan('binance:sync')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('X-MBX-APIKEY', 'key-'.$a->id));
        Http::assertSent(fn ($request) => $request->hasHeader('X-MBX-APIKEY', 'key-'.$b->id));
        $this->assertSame(2, Portfolio::query()->where('status', PortfolioStatus::Running)->count());
    }
}
