<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Brokers\BinanceBroker;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\BinanceClient;
use App\Trading\Live\LiveTradingException;
use App\Trading\TradingHalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            '*/api/v3/account*' => fn () => Http::response(['balances' => $this->balances]),
            '*/api/v3/allOrders*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $orders = $this->remoteOrders[$query['symbol'] ?? ''] ?? [];

                return $orders === 'invalido' ? Http::response(['code' => -1121, 'msg' => 'Invalid symbol.'], 400) : Http::response($orders);
            }]);
    }

    /** @var array<string, list<array<string, mixed>>|string> /api/v3/allOrders answer per pair */
    private array $remoteOrders = [];

    /** @return array<string, mixed> one order as /api/v3/allOrders lists it */
    private function remoteOrder(string $symbol, int $id, string $side, string $status, array $extra = []): array
    {
        return $extra + [
            'symbol' => $symbol,
            'orderId' => $id,
            'clientOrderId' => 'web_'.$id,
            'price' => '61000.00',
            'origQty' => '0.01',
            'executedQty' => $status === 'FILLED' ? '0.01' : '0',
            'cummulativeQuoteQty' => $status === 'FILLED' ? '610' : '0',
            'status' => $status,
            'type' => 'LIMIT',
            'side' => $side,
            'time' => 1_760_800_000_000 + $id * 60_000,
        ];
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

    public function test_sync_lists_every_balance_with_value_reason_and_hour()
    {
        $user = User::factory()->create();
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential($user));
        $this->fakeAccount([
            ['asset' => 'USDT', 'free' => '10', 'locked' => '0'],
            ['asset' => 'BTC', 'free' => '0.01', 'locked' => '0.005'],
            ['asset' => 'XRP', 'free' => '100', 'locked' => '50'],
            ['asset' => 'SHIB', 'free' => '1000', 'locked' => '0'],
            ['asset' => 'ABC', 'free' => '5', 'locked' => '0'],
            ['asset' => 'XYZ', 'free' => '3', 'locked' => '0'],
        ]);
        Http::fake([
            '*/api/v3/account*' => Http::response(['balances' => $this->balances]),
            '*/api/v3/ticker/price*' => Http::response([
                ['symbol' => 'XRPUSDT', 'price' => '0.5'],
                ['symbol' => 'SHIBUSDT', 'price' => '0.00001'],
                ['symbol' => 'BTCUSDT', 'price' => '60000'],
                ['symbol' => 'ABCBTC', 'price' => '0.00001'],
            ]),
        ]);

        $result = app(BinanceAccountSync::class)->sync($portfolio);
        $portfolio->refresh();
        $report = collect($portfolio->sync_report['holdings'])->keyBy('coin');

        $this->assertNotNull($portfolio->synced_at);
        $this->assertSame(2, $result['positions']);
        $this->assertSame('posicao', $report['XRP']['status']);
        $this->assertEqualsWithDelta(75.0, $report['XRP']['value'], 1e-6);
        $this->assertFalse(Asset::query()->where('symbol', 'XRPUSDT')->sole()->active);
        $this->assertSame('poeira', $report['SHIB']['status']);
        $this->assertSame('sem_par_usdt', $report['ABC']['status']);
        $this->assertSame('sem_cotacao', $report['XYZ']['status']);
        $this->assertEqualsCanonicalizing(['SHIB', 'ABC', 'XYZ'], $result['ignored']);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page
                ->has('positions', 2)
                ->where('account_sync.dust.0.coin', 'SHIB')
                ->has('account_sync.not_mirrored', 2));
    }

    public function test_sync_reads_every_wallet_of_the_account_so_the_screen_shows_the_binance_total()
    {
        $user = User::factory()->create();
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential($user));
        $this->fakeAccount([
            ['asset' => 'USDT', 'free' => '10', 'locked' => '5'],
            ['asset' => 'SHIB', 'free' => '1000', 'locked' => '0'],
            ['asset' => 'ABC', 'free' => '5', 'locked' => '0'],
        ]);
        Http::fake([
            '*/sapi/v1/asset/wallet/balance*' => Http::response([
                ['activate' => true, 'balance' => '15.04', 'walletName' => 'Spot'],
                ['activate' => true, 'balance' => '3.5', 'walletName' => 'Funding'],
                ['activate' => true, 'balance' => '0', 'walletName' => 'Cross Margin'],
                ['activate' => true, 'balance' => '1.25', 'walletName' => 'Earn'],
            ]),
            '*/api/v3/ticker/price*' => Http::response([
                ['symbol' => 'SHIBUSDT', 'price' => '0.00001'],
                ['symbol' => 'BTCUSDT', 'price' => '60000'],
                ['symbol' => 'ABCBTC', 'price' => '0.00001'],
            ]),
        ]);

        app(BinanceAccountSync::class)->sync($portfolio);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sapi/v1/asset/wallet/balance') && str_contains($request->url(), 'quoteAsset=USDT') && $request->hasHeader('X-MBX-APIKEY', 'key-'.$user->id));
        $this->assertEqualsWithDelta(15.0, $portfolio->fresh()->cash, 1e-6);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page
                ->where('portfolio.equity', 15)
                ->where('account_sync.wallets', ['Spot' => 15.04, 'Funding' => 3.5, 'Earn' => 1.25])
                ->where('account_sync.dust_total', 0.01)
                ->where('account_sync.not_mirrored_total', 3));
    }

    public function test_sync_still_mirrors_spot_when_the_wallet_endpoint_fails()
    {
        $user = User::factory()->create();
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential($user));
        $this->fakeAccount([['asset' => 'USDT', 'free' => '12', 'locked' => '0']]);
        Http::fake(['*/sapi/v1/asset/wallet/balance*' => Http::response(['code' => -2015, 'msg' => 'Invalid API-key, IP, or permissions for action.'], 401)]);

        app(BinanceAccountSync::class)->sync($portfolio);

        $this->assertEqualsWithDelta(12.0, $portfolio->fresh()->cash, 1e-6);
        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page->where('account_sync.wallets', null));
    }

    public function test_sync_brings_the_last_orders_of_each_pair_with_their_state()
    {
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT']);
        $user = User::factory()->create();
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential($user));
        $portfolio->assets()->attach($eth->id);
        $mine = Order::create([
            'portfolio_id' => $portfolio->id, 'asset_id' => $this->btc->id, 'broker' => 'binance', 'external_id' => 'BTCUSDT:23',
            'side' => OrderSide::Buy, 'type' => OrderType::Market, 'qty' => 0.01, 'status' => OrderStatus::Filled,
        ]);
        $this->fakeAccount([['asset' => 'BTC', 'free' => '0.01', 'locked' => '0']]);
        $btcOrders = array_map(fn (int $id) => $this->remoteOrder('BTCUSDT', $id, $id % 2 ? 'BUY' : 'SELL', 'CANCELED'), range(1, 25));
        $btcOrders[22] = $this->remoteOrder('BTCUSDT', 23, 'BUY', 'FILLED', ['clientOrderId' => 'signal-9', 'type' => 'MARKET', 'price' => '0']);
        $btcOrders[24] = $this->remoteOrder('BTCUSDT', 25, 'SELL', 'NEW');
        $this->remoteOrders = ['BTCUSDT' => $btcOrders, 'ETHUSDT' => 'invalido'];

        $result = app(BinanceAccountSync::class)->sync($portfolio);
        $report = $portfolio->fresh()->sync_report;
        $orders = collect($report['orders'])->keyBy('id');

        $this->assertSame(20, $result['orders']);
        $this->assertSame(1, $result['positions']);
        $this->assertSame('pending', $orders['BTCUSDT:25']['status']);
        $this->assertSame('sell', $orders['BTCUSDT:25']['side']);
        $this->assertFalse($orders['BTCUSDT:25']['from_app']);
        $this->assertFalse($orders->has('BTCUSDT:5'), 'Só as 20 mais novas do par.');
        $this->assertSame('BTCUSDT:25', $report['orders'][0]['id']);
        $this->assertSame('filled', $orders['BTCUSDT:23']['status']);
        $this->assertTrue($orders['BTCUSDT:23']['from_app']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v3/allOrders') && str_contains($request->url(), 'limit=20'));
        $this->assertArrayHasKey('ETHUSDT', $report['order_errors']);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page
                ->has('account_sync.orders', 20)
                ->where('account_sync.orders.0.id', 'BTCUSDT:25')
                ->has('account_sync.order_errors.ETHUSDT'));
        $this->assertSame($mine->id, collect($portfolio->fresh()->sync_report['orders'])->firstWhere('id', 'BTCUSDT:23')['app_order_id'] ?? null);
    }

    public function test_sync_books_a_resting_sell_that_binance_executed_even_during_the_halt()
    {
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential(User::factory()->create()));
        $this->fakeAccount([['asset' => 'BTC', 'free' => '0.01', 'locked' => '0']]);
        app(BinanceAccountSync::class)->sync($portfolio);
        $position = $portfolio->openPositions()->sole();
        $position->update(['avg_price' => 60_000]);
        $sell = Order::create([
            'portfolio_id' => $portfolio->id, 'asset_id' => $this->btc->id, 'position_id' => $position->id, 'broker' => 'binance',
            'external_id' => 'BTCUSDT:77', 'side' => OrderSide::Sell, 'type' => OrderType::Limit, 'qty' => 0.01, 'price' => 61_000,
            'status' => OrderStatus::Pending, 'reason' => 'micro',
        ]);
        app(TradingHalt::class)->halt('teste', 'Parada para o teste.');
        $this->fakeAccount([['asset' => 'USDT', 'free' => '610', 'locked' => '0']]);
        Http::fake(['*/api/v3/order?*' => Http::response($this->remoteOrder('BTCUSDT', 77, 'SELL', 'FILLED', ['fills' => []]))]);

        $result = app(BinanceAccountSync::class)->sync($portfolio);

        $this->assertSame(1, $result['settled']);
        $this->assertSame(OrderStatus::Filled, $sell->fresh()->status);
        $this->assertEqualsWithDelta(0.01, (float) $sell->fills()->sum('qty'), 1e-9);
        $position->refresh();
        $this->assertNotNull($position->closed_at);
        $this->assertSame('micro', $position->exit_reason);
        $this->assertEqualsWithDelta(10, $position->realized_pnl, 1e-6);
        $this->assertEqualsWithDelta(610, $portfolio->fresh()->cash, 1e-6);
    }

    public function test_sync_reads_only_the_owners_orders_with_the_owners_key()
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $theirs = app(BinanceAccountSync::class)->portfolioFor($this->credential($owner));
        $mine = app(BinanceAccountSync::class)->portfolioFor($this->credential($intruder));
        $theirs->update(['sync_report' => ['holdings' => [], 'orders' => [$this->remoteOrder('BTCUSDT', 1, 'BUY', 'FILLED')], 'order_errors' => []]]);
        $this->fakeAccount([['asset' => 'BTC', 'free' => '0.01', 'locked' => '0']]);

        $this->actingAs($intruder)->post(route('binance.sync'))->assertRedirect();
        $this->actingAs($intruder)->get(route('portfolios.show', $theirs))->assertForbidden();

        Http::assertNotSent(fn ($request) => $request->hasHeader('X-MBX-APIKEY', 'key-'.$owner->id));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v3/allOrders') && $request->hasHeader('X-MBX-APIKEY', 'key-'.$intruder->id));
        $this->assertCount(1, $theirs->fresh()->sync_report['orders']);
        $this->assertSame([], $mine->fresh()->sync_report['orders']);
    }

    public function test_other_user_cannot_see_the_synced_portfolio_or_trigger_its_sync()
    {
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential(User::factory()->create()));
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('portfolios.show', $portfolio))->assertForbidden();
        $this->actingAs($intruder)->post(route('binance.sync'))->assertNotFound();
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

    public function test_sync_keeps_the_same_positions_while_the_balance_does_not_change()
    {
        // Asset ids that differ from the position ids: the bug only showed when they did not match.
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT']);
        $sol = Asset::factory()->create(['symbol' => 'SOLUSDT']);
        $this->quote($eth, 2_400);
        $this->quote($sol, 100);
        $portfolio = app(BinanceAccountSync::class)->portfolioFor($this->credential(User::factory()->create()));
        $this->fakeAccount([
            ['asset' => 'ETH', 'free' => '0.01', 'locked' => '0'],
            ['asset' => 'SOL', 'free' => '0.5', 'locked' => '0'],
        ]);

        app(BinanceAccountSync::class)->sync($portfolio);
        $portfolio->openPositions()->update(['avg_price' => 90]);
        $first = $portfolio->openPositions()->orderBy('asset_id')->pluck('id', 'asset_id')->all();

        app(BinanceAccountSync::class)->sync($portfolio);
        app(BinanceAccountSync::class)->sync($portfolio);

        $this->assertSame($first, $portfolio->openPositions()->orderBy('asset_id')->pluck('id', 'asset_id')->all());
        $this->assertSame([$eth->id, $sol->id], array_keys($first));
        $this->assertSame(2, $portfolio->positions()->count());
        $this->assertSame(0, $portfolio->positions()->where('exit_reason', 'conta')->count());
        $this->assertEqualsWithDelta(90, $portfolio->openPositions()->where('asset_id', $sol->id)->sole()->avg_price, 1e-6);
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

    private function clientFor(User $user): BinanceClient
    {
        return new BinanceClient($this->credential($user));
    }

    /** @return list<array<string, string>> query strings of the signed /account calls, in order */
    private function accountTimestamps(): array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/api/v3/account'))
            ->values()->map(function ($pair) {
                parse_str(parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

                return $query;
            })->all();
    }

    public function test_clock_ahead_of_binance_is_compensated_with_a_negative_offset()
    {
        Cache::flush();
        $serverTime = (int) (microtime(true) * 1000) - 5_000; // relógio local 5 s adiantado
        Http::fake([
            '*/api/v3/time' => Http::response(['serverTime' => $serverTime]),
            '*/api/v3/account*' => Http::response(['balances' => []]),
        ]);

        $this->clientFor(User::factory()->create())->signed('GET', '/api/v3/account');

        [$query] = $this->accountTimestamps();
        $this->assertEqualsWithDelta($serverTime, (int) $query['timestamp'], 1_000);
        $this->assertLessThan($serverTime + 1_000, (int) $query['timestamp']);
        $this->assertSame('5000', $query['recvWindow']);
        $this->assertArrayHasKey('signature', $query);
    }

    public function test_clock_behind_binance_is_compensated_with_a_positive_offset_and_cached()
    {
        Cache::flush();
        $serverTime = (int) (microtime(true) * 1000) + 3_000; // relógio local 3 s atrasado
        Http::fake([
            '*/api/v3/time' => Http::response(['serverTime' => $serverTime]),
            '*/api/v3/account*' => Http::response(['balances' => []]),
        ]);

        $client = $this->clientFor(User::factory()->create());
        $client->signed('GET', '/api/v3/account');
        $client->signed('GET', '/api/v3/account');

        foreach ($this->accountTimestamps() as $query) {
            $this->assertEqualsWithDelta($serverTime, (int) $query['timestamp'], 1_000);
        }
        Http::assertSentCount(3); // um /time e duas chamadas assinadas
    }

    public function test_timestamp_error_refreshes_the_offset_and_retries_once()
    {
        Cache::flush();
        $skewed = (int) (microtime(true) * 1000) + 60_000;
        Cache::put('binance.clock_offset.'.md5((string) config('trading.binance.base_url')), 0, now()->addMinutes(5));
        Http::fake([
            '*/api/v3/time' => Http::response(['serverTime' => $skewed]),
            '*/api/v3/account*' => Http::sequence()
                ->push(['code' => -1021, 'msg' => "Timestamp for this request was 1000ms ahead of the server's time."], 400)
                ->push(['balances' => []]),
        ]);

        $json = $this->clientFor(User::factory()->create())->signed('GET', '/api/v3/account');

        $this->assertSame(['balances' => []], $json);
        $timestamps = array_map(fn ($q) => (int) $q['timestamp'], $this->accountTimestamps());
        $this->assertCount(2, $timestamps);
        $this->assertGreaterThan(50_000, $timestamps[1] - $timestamps[0]);
    }

    public function test_persistent_timestamp_error_gives_up_after_one_retry_with_a_clear_message()
    {
        Cache::flush();
        Http::fake([
            '*/api/v3/time' => fn () => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            '*/api/v3/account*' => Http::response(['code' => -1021, 'msg' => 'Timestamp outside of recvWindow.'], 400),
        ]);

        try {
            $this->clientFor(User::factory()->create())->signed('GET', '/api/v3/account');
            $this->fail('Deveria falhar com -1021.');
        } catch (LiveTradingException $e) {
            $this->assertStringContainsString('relógio', $e->getMessage());
            $this->assertStringContainsString('hwclock', $e->getMessage());
        }

        $this->assertCount(2, $this->accountTimestamps());
    }
}
