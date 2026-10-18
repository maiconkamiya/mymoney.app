<?php

namespace Tests\Feature\Trading;

use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\BrokerCredential;
use App\Models\Candle;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Models\User;
use App\Trading\Data\OrderRequest;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\OrderReconciler;
use App\Trading\Micro\MicroCosts;
use App\Trading\Micro\MicroOverview;
use App\Trading\Micro\MicroRanker;
use App\Trading\Micro\MicroTargetOdds;
use App\Trading\Micro\MicroTrader;
use App\Trading\OrderExecutor;
use App\Trading\PortfolioValuation;
use App\Trading\PositionExits;
use App\Trading\Risk\RiskGuard;
use App\Trading\TradingHalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MicroOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->asset = Asset::factory()->create(['symbol' => 'DOGEUSDT']);
    }

    /**
     * 120 one-minute candles ending now: flat, one spike, then a slow climb near the support.
     */
    private function candles(Asset $asset, float $end = 100.15): void
    {
        $rows = [];

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + ($end - 100.0) * ($i - 94) / 25;
            $rows[] = [
                'asset_id' => $asset->id,
                'timeframe' => '1m',
                'open_at' => now()->startOfMinute()->subMinutes(119 - $i),
                'open' => $close,
                'high' => $close + 0.05 + ($i === 80 ? 0.9 : 0),
                'low' => $close - 0.05,
                'close' => $close,
                'volume' => 1,
            ];
        }

        Candle::insert($rows);
    }

    private function micro(array $attributes = []): Portfolio
    {
        return Portfolio::factory()->create($attributes + [
            'micro_enabled' => true,
            'micro_order_value' => 20,
            'micro_net_margin_pct' => 0.2,
        ]);
    }

    private function advanceTo(float $price): void
    {
        Candle::where('asset_id', $this->asset->id)->latest('open_at')->first()->update(['open' => $price, 'high' => $price, 'low' => $price, 'close' => $price]);
    }

    public function test_costs_add_fees_and_slippage_to_the_net_margin()
    {
        $costs = new MicroCosts;

        $this->assertEqualsWithDelta(0.3, $costs->roundTripPct(), 0.0001);
        $this->assertEqualsWithDelta(0.5, $costs->grossMarginPct(0.2), 0.0001);
    }

    public function test_opens_a_cycle_with_target_and_stop_and_closes_it_at_the_target_with_net_profit()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $cycle = MicroCycle::sole();
        $position = $portfolio->openPositions()->sole();
        $this->assertSame('open', $cycle->status);
        $this->assertEqualsWithDelta($cycle->entry_price * 1.005, $cycle->target_price, 0.0001);
        $this->assertEqualsWithDelta($cycle->entry_price * 0.995, $cycle->stop_price, 0.0001);
        $this->assertEqualsWithDelta($cycle->target_price, $position->target_price, 0.0001);
        $this->assertEqualsWithDelta(20, $cycle->qty * $cycle->entry_price, 0.5);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.opened', 'subject_id' => $cycle->id]);

        // One cycle per pair at a time.
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));

        $this->advanceTo(101.0);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $cycle->refresh();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('target', $cycle->exit_reason);
        $this->assertGreaterThan(0, $cycle->net_pnl);
        $this->assertGreaterThan($cycle->fees, $cycle->qty * ($cycle->exit_price - $cycle->entry_price));
        $this->assertEqualsWithDelta($cycle->qty * ($cycle->exit_price - $cycle->entry_price) - $cycle->fees, $cycle->net_pnl, 0.000001);
    }

    public function test_a_stop_books_a_loss_and_pauses_that_pair()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $this->advanceTo(99.0);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $cycle = MicroCycle::sole();
        $this->assertSame('stop', $cycle->exit_reason);
        $this->assertLessThan(0, $cycle->net_pnl);

        // Chart back to an entry setup right after the stop: the cooldown holds the pair.
        Candle::where('asset_id', $this->asset->id)->delete();
        $this->candles($this->asset);
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
    }

    public function test_a_cycle_held_too_long_is_sold()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        MicroCycle::query()->update(['opened_at' => now()->subHours(2)]);

        app(MicroTrader::class)->run($portfolio);

        $cycle = MicroCycle::oldest('id')->first();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('micro_time', $cycle->exit_reason);
    }

    public function test_stops_for_the_day_after_the_target_or_the_loss_limit()
    {
        $this->candles($this->asset);
        $target = $this->micro(['micro_daily_target' => 1]);
        MicroCycle::create(['portfolio_id' => $target->id, 'asset_id' => $this->asset->id, 'status' => 'closed', 'qty' => 1, 'entry_price' => 1, 'target_price' => 1, 'stop_price' => 1, 'net_pnl' => 1.5, 'gross_margin_pct' => 0.5, 'opened_at' => now(), 'closed_at' => now()]);

        $this->assertSame('target_hit', app(MicroTrader::class)->state($target)['code']);
        $this->assertSame(0, app(MicroTrader::class)->run($target));

        $loss = $this->micro(['micro_daily_loss_limit' => 2]);
        MicroCycle::create(['portfolio_id' => $loss->id, 'asset_id' => $this->asset->id, 'status' => 'closed', 'qty' => 1, 'entry_price' => 1, 'target_price' => 1, 'stop_price' => 1, 'net_pnl' => -2.5, 'gross_margin_pct' => 0.5, 'opened_at' => now(), 'closed_at' => now()]);

        $this->assertSame('loss_limit', app(MicroTrader::class)->state($loss)['code']);
        $this->assertSame(0, app(MicroTrader::class)->run($loss));
        $this->assertSame(0, Order::where('portfolio_id', $loss->id)->count());
    }

    public function test_does_nothing_when_off_or_when_the_chart_gives_no_entry()
    {
        $this->candles($this->asset, 101.0);

        $this->assertSame(0, app(MicroTrader::class)->run($this->micro()));
        $this->assertSame(0, app(MicroTrader::class)->run($this->micro(['micro_enabled' => false])));
        $this->assertSame(0, Order::count());
    }

    public function test_stops_for_the_day_after_the_chosen_number_of_operations()
    {
        $other = Asset::factory()->create(['symbol' => 'ADAUSDT']);
        $this->candles($this->asset);
        $this->candles($other);
        $portfolio = $this->micro(['micro_order_value' => 1, 'micro_daily_operations' => 1]);

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));
        $this->assertSame(1, MicroCycle::count());
        $this->assertEqualsWithDelta(1, MicroCycle::sole()->qty * MicroCycle::sole()->entry_price, 0.05);
        // The buy and its resting sale are one operation: only the buy counts.
        $this->assertSame(2, Order::count());
        $this->assertSame(1, app(MicroTrader::class)->openedToday($portfolio));

        $this->assertSame('operations_done', app(MicroTrader::class)->state($portfolio)['code']);
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertSame(1, MicroCycle::count());
    }

    public function test_explain_says_why_each_asset_does_or_does_not_enter_and_sends_nothing()
    {
        $flat = Asset::factory()->create(['symbol' => 'XRPUSDT']);
        Asset::factory()->create(['symbol' => 'NOCANDLES']);
        $this->candles($this->asset);
        $this->candles($flat, 101.0);
        $this->micro();

        Artisan::call('micro:run', ['--explain' => true]);
        $output = Artisan::output();

        foreach (['Operando', 'DOGEUSDT', 'ENTRA', 'XRPUSDT', 'Longe do suporte', 'Sem candles de 1 minuto'] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_a_pair_the_account_already_holds_is_blocked_and_the_reason_is_shown()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'qty' => 1, 'avg_price' => 100, 'opened_at' => now()]);

        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertStringContainsString('DOGEUSDT: a carteira já tem posição aberta neste par', (string) app(MicroTrader::class)->lastBlock($portfolio));

        Artisan::call('micro:run', ['--explain' => true]);
        $this->assertStringContainsString('BLOQUEADO: a carteira já tem posição aberta', Artisan::output());

        $focus = app(MicroOverview::class)->for($portfolio)['focus'][0];
        $this->assertStringContainsString('posição aberta', $focus['blocked']);
    }

    public function test_the_focus_cards_list_each_entry_condition_and_other_users_get_403()
    {
        $flat = Asset::factory()->create(['symbol' => 'XRPUSDT']);
        $this->candles($this->asset);
        $this->candles($flat, 101.0);
        $portfolio = $this->micro();

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();

        $focus = collect(app(MicroOverview::class)->for($portfolio)['focus'])->keyBy('symbol');
        $this->assertSame(['trend', 'support', 'reach', 'room'], array_column($focus['DOGEUSDT']['checks'], 'key'));
        $this->assertTrue($focus['DOGEUSDT']['entry']);
        $this->assertSame([true, true, true, true], array_column($focus['DOGEUSDT']['checks'], 'ok'));

        $support = collect($focus['XRPUSDT']['checks'])->firstWhere('key', 'support');
        $this->assertFalse($support['ok']);
        $this->assertStringContainsString('acima do suporte', $support['detail']);
        $this->assertStringStartsWith('Longe do suporte', $focus['XRPUSDT']['reason']);

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->has('micro.focus.0.checks', 4));
    }

    public function test_the_risk_guard_reason_is_shown_before_any_order_is_sent()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $portfolio->riskProfile->update(['max_open_positions' => 0]);

        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertStringContainsString('guarda de risco: Limite de posições abertas atingido.', (string) app(MicroTrader::class)->lastBlock($portfolio));
        $this->assertSame(0, Order::count());

        Artisan::call('micro:run', ['--explain' => true]);
        $this->assertStringContainsString('BLOQUEADO: guarda de risco', Artisan::output());
    }

    public function test_an_opened_cycle_clears_the_old_block_and_explain_says_the_order_would_go()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        Cache::put('micro:last-block:'.$portfolio->id, 'velho', now()->addHour());

        Artisan::call('micro:run', ['--explain' => true]);
        $this->assertStringContainsString('a ordem sairia no próximo minuto', Artisan::output());

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));
        $this->assertNull(app(MicroTrader::class)->lastBlock($portfolio));
    }

    public function test_the_order_has_the_value_of_the_stake_whatever_the_profile_percentage_per_order()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro(['micro_order_value' => 20]);
        $equity = app(PortfolioValuation::class)->equity($portfolio);
        // O perfil só deixaria 0,5 USDT por ordem; a micro operação usa o aporte inteiro.
        $portfolio->riskProfile->update(['max_order_pct' => 0.5 / $equity]);

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));
        $this->assertEqualsWithDelta(20, MicroCycle::sole()->qty * MicroCycle::sole()->entry_price, 0.5);
    }

    public function test_the_profile_percentage_still_cuts_an_order_that_comes_from_an_agent_signal()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $equity = app(PortfolioValuation::class)->equity($portfolio);
        $portfolio->riskProfile->update(['max_order_pct' => 0.5 / $equity]);
        $request = new OrderRequest($this->asset, OrderSide::Buy, 0.2, stopPrice: 90.0);

        $decision = app(RiskGuard::class)->check($portfolio, $request);
        $this->assertTrue($decision->allowed);
        $this->assertLessThan(0.2, $decision->order->qty);

        $owner = app(RiskGuard::class)->check($portfolio, $request, ownerSized: true);
        $this->assertEqualsWithDelta(0.2, $owner->order->qty, 0.0001);
    }

    public function test_the_wallet_percentage_limits_the_open_cycles_and_a_sale_gives_the_room_back()
    {
        $other = Asset::factory()->create(['symbol' => 'ADAUSDT']);
        $this->candles($this->asset);
        $this->candles($other);
        $portfolio = $this->micro(['micro_order_value' => 20, 'micro_wallet_pct' => 1]);
        $portfolio->update(['cash' => 1000, 'initial_cash' => 1000]);

        // 1% de 1000 é menos que um aporte de 20: nada abre.
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertStringContainsString('limite de 1% do saldo', (string) app(MicroTrader::class)->lastBlock($portfolio));

        $portfolio->update(['micro_wallet_pct' => 100]);
        $this->assertSame(2, app(MicroTrader::class)->run($portfolio), (string) app(MicroTrader::class)->lastBlock($portfolio));
        $budget = app(MicroTrader::class)->walletBudget($portfolio);
        $this->assertEqualsWithDelta(40, $budget['used'], 1.0);
    }

    public function test_the_quantity_is_rounded_up_to_the_step_so_the_value_never_falls_under_the_order()
    {
        $this->asset->update(['lot_size' => '0.01']);
        $this->candles($this->asset);
        $portfolio = $this->micro(['micro_order_value' => 5]);

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $cycle = MicroCycle::sole();
        $this->assertGreaterThanOrEqual(5, $cycle->qty * $cycle->entry_price);
        $this->assertEqualsWithDelta(0.0, fmod(round($cycle->qty * 100, 6), 1.0), 0.00001);
    }

    public function test_the_overview_shows_the_last_run_and_flags_a_stopped_scheduler()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $owner = $portfolio->user;

        $this->actingAs($owner)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.scheduler_stale', true)
            ->where('micro.last_run', null));

        app(MicroTrader::class)->run($portfolio);

        $this->actingAs($owner)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.scheduler_stale', false)
            ->has('micro.last_run'));
    }

    public function test_the_overview_warns_when_the_margin_is_beyond_what_the_pairs_move()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro(['micro_net_margin_pct' => 3]);

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.margin_warning', fn ($text) => str_contains($text, 'Tente margem líquida de até')));

        $ok = $this->micro(['micro_net_margin_pct' => 0.2]);

        $this->actingAs($ok->user)->get(route('portfolios.show', $ok))->assertInertia(fn (Assert $page) => $page
            ->where('micro.margin_warning', null));
    }

    public function test_only_looks_at_the_assets_the_portfolio_tracks()
    {
        $other = Asset::factory()->create(['symbol' => 'ADAUSDT']);
        $this->candles($this->asset);
        $this->candles($other);
        $portfolio = $this->micro();
        $portfolio->assets()->attach($other);

        app(MicroTrader::class)->run($portfolio);

        $this->assertSame($other->id, MicroCycle::sole()->asset_id);
    }

    public function test_real_portfolio_without_confirmed_auto_execution_sends_nothing()
    {
        config(['trading.live.enabled' => true]);
        $this->candles($this->asset);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);
        $portfolio = $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id]);

        $this->assertSame('live_not_confirmed', app(MicroTrader::class)->state($portfolio)['code']);
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertSame(0, Order::count());
    }

    public function test_the_command_runs_every_enabled_crypto_portfolio()
    {
        $this->candles($this->asset);
        $this->micro();
        $this->micro(['micro_enabled' => false]);

        $this->artisan('micro:run')->assertSuccessful();

        $this->assertSame(1, MicroCycle::count());
        $this->assertSame(OrderStatus::Filled, Order::where('side', OrderSide::Buy)->sole()->status);
        $this->assertSame(OrderStatus::Pending, Order::where('side', OrderSide::Sell)->sole()->status);
    }

    // --- Venda limitada registrada logo após a compra ---------------------

    public function test_the_target_sale_is_registered_right_after_the_buy_and_fills_at_its_own_price()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $cycle = MicroCycle::sole();
        $sell = Order::where('side', OrderSide::Sell)->sole();
        $this->assertSame(OrderType::Limit, $sell->type);
        $this->assertSame(OrderStatus::Pending, $sell->status);
        $this->assertSame('target', $sell->reason);
        // Up to the 0.01 tick, never under the target.
        $this->assertEqualsWithDelta(ceil(round($cycle->target_price / 0.01, 8)) * 0.01, $sell->price, 1e-8);
        $this->assertGreaterThanOrEqual($cycle->target_price, $sell->price);
        $this->assertEqualsWithDelta($cycle->qty, $sell->qty, 1e-8);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.target_order_placed', 'subject_id' => $cycle->id]);
        $this->assertSame($sell->price, app(MicroOverview::class)->for($portfolio)['cycles'][0]['resting_sell_price']);

        // Just under the limit: it keeps resting, nothing is sold at market.
        $this->advanceTo($sell->price - 0.01);
        app(PositionExits::class)->checkStopsAndTargets();
        $this->assertSame(OrderStatus::Pending, $sell->fresh()->status);
        $this->assertSame(2, Order::count());

        $this->advanceTo(101.0);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $this->assertSame(OrderStatus::Filled, $sell->fresh()->status);
        $this->assertSame(2, Order::count());
        $cycle->refresh();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('target', $cycle->exit_reason);
        $this->assertEqualsWithDelta($sell->price, $cycle->exit_price, 1e-8);
        $this->assertGreaterThan(0, $cycle->net_pnl);
    }

    public function test_the_stop_cancels_the_resting_sale_before_selling_at_market()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        $limit = Order::where('side', OrderSide::Sell)->sole();

        $this->advanceTo(99.0);
        app(PositionExits::class)->checkStopsAndTargets();

        $this->assertSame(OrderStatus::Cancelled, $limit->fresh()->status);
        $market = Order::where('side', OrderSide::Sell)->where('type', OrderType::Market)->sole();
        $this->assertSame(OrderStatus::Filled, $market->status);
        $this->assertSame('stop', $market->reason);
    }

    public function test_the_time_limit_cancels_the_resting_sale_before_selling_at_market()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        MicroCycle::query()->update(['opened_at' => now()->subHours(2)]);

        app(MicroTrader::class)->run($portfolio);

        $this->assertSame(OrderStatus::Cancelled, Order::where('side', OrderSide::Sell)->where('type', OrderType::Limit)->oldest('id')->first()->status);
        $this->assertSame('micro_time', MicroCycle::oldest('id')->first()->exit_reason);
    }

    public function test_the_resting_sale_of_an_ended_cycle_does_not_close_the_next_cycle()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        $first = MicroCycle::sole();
        $old = Order::where('side', OrderSide::Sell)->sole();
        $this->assertSame($first->position_id, $old->position_id);

        // The account sync ended the first cycle while its sale kept resting at the exchange (bug of #108).
        $first->position->update(['closed_at' => now(), 'exit_reason' => 'conta']);
        app(MicroTrader::class)->settle($portfolio);
        app(MicroTrader::class)->run($portfolio);
        $second = MicroCycle::where('status', 'open')->sole();
        $own = Order::where('side', OrderSide::Sell)->whereKeyNot($old->id)->sole();
        $qty = $second->position->qty;

        // Only the old sale is reached: it fills and books its execution, the new cycle stays as it was.
        $old->update(['price' => $own->price - 0.05]);
        $this->advanceTo($own->price - 0.02);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $this->assertSame(OrderStatus::Filled, $old->fresh()->status);
        $this->assertEqualsWithDelta($old->qty, $old->fills()->sum('qty'), 1e-8);
        $this->assertSame(OrderStatus::Pending, $own->fresh()->status);
        $this->assertSame('open', $second->fresh()->status);
        $this->assertNull($second->position->fresh()->closed_at);
        $this->assertEqualsWithDelta($qty, $second->position->fresh()->qty, 1e-8);

        // Its own sale closes it at its own price.
        $this->advanceTo($own->price + 0.01);
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $second->refresh();
        $this->assertSame('target', $second->exit_reason);
        $this->assertEqualsWithDelta($own->price, $second->exit_price, 1e-8);
    }

    /**
     * A real portfolio with micro operations on, and a fake Binance whose
     * resting sell answers `$sellState` when queried.
     */
    private function liveMicro(string &$sellState): Portfolio
    {
        config(['trading.live.enabled' => true]);
        $this->asset->update(['lot_size' => '0.0001', 'tick_size' => '0.01', 'min_qty' => '0.0001', 'min_notional' => '5']);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);

        Http::fake(function (Request $request) use (&$sellState) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $url = $request->url();

            return match (true) {
                $path === '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
                $path === '/api/v3/ticker/price' => Http::response([]),
                // The buy fee came out in DOGE: 0.1999 bought, 0.19970001 on the account.
                $path === '/api/v3/account' => Http::response(['balances' => [['asset' => 'DOGE', 'free' => '0.19970001', 'locked' => '0'], ['asset' => 'USDT', 'free' => '30', 'locked' => '0']]]),
                $request->method() === 'POST' && str_contains($url, 'type=LIMIT') => Http::response(['orderId' => 2, 'status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0', 'fills' => []]),
                $request->method() === 'POST' => Http::response(['orderId' => str_contains($url, 'side=BUY') ? 1 : 3, 'status' => 'FILLED', 'executedQty' => '0.1999', 'cummulativeQuoteQty' => '20.02', 'transactTime' => now()->getTimestampMs(), 'fills' => [['price' => '100.15', 'qty' => '0.1999', 'commission' => str_contains($url, 'side=BUY') ? '0.0002' : '0.02', 'commissionAsset' => str_contains($url, 'side=BUY') ? 'DOGE' : 'USDT']]]),
                $request->method() === 'DELETE' => Http::response(['orderId' => 2, 'status' => $sellState = 'CANCELED']),
                str_contains($url, 'orderId=2') => Http::response(['orderId' => 2, 'status' => $sellState, 'executedQty' => $sellState === 'FILLED' ? '0.1997' : '0', 'cummulativeQuoteQty' => $sellState === 'FILLED' ? '20.12' : '0', 'transactTime' => now()->getTimestampMs()]),
                default => Http::response(['orderId' => 1, 'status' => 'FILLED', 'executedQty' => '0.1999', 'cummulativeQuoteQty' => '20.02']),
            };
        });

        return $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id, 'auto_execute' => true, 'live_auto_confirmed_at' => now(), 'cash' => 50, 'initial_cash' => 50]);
    }

    public function test_a_real_cycle_leaves_a_limit_sale_on_binance_with_the_free_quantity_and_closes_when_it_executes()
    {
        $this->candles($this->asset);
        $sellState = 'NEW';
        $portfolio = $this->liveMicro($sellState);

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $cycle = MicroCycle::sole();
        $limit = Order::where('side', OrderSide::Sell)->sole();
        $price = ceil(round($cycle->target_price / 0.01, 8)) * 0.01;
        // The free balance after the fee, rounded down to the 0.0001 step, at the target rounded up to the tick.
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'type=LIMIT') && str_contains($request->url(), 'side=SELL') && str_contains($request->url(), 'quantity=0.1997&') && str_contains($request->url(), 'price='.rtrim(rtrim(number_format($price, 8, '.', ''), '0'), '.').'&') && str_contains($request->url(), 'timeInForce=GTC'));
        $this->assertSame(OrderStatus::Pending, $limit->status);

        // Still resting: the reconciliation sees no divergence and the account sync keeps the position.
        $this->assertSame([], app(OrderReconciler::class)->reconcile());
        app(PositionExits::class)->checkStopsAndTargets();
        $this->assertNull($cycle->position->fresh()->closed_at);

        // Binance executed it: the exits job books the sale and the cycle closes at the limit price.
        $sellState = 'FILLED';
        app(PositionExits::class)->checkStopsAndTargets();
        app(MicroTrader::class)->settle($portfolio);

        $this->assertSame(OrderStatus::Filled, $limit->fresh()->status);
        $this->assertSame(0, Order::where('side', OrderSide::Sell)->where('type', OrderType::Market)->count());
        $cycle->refresh();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('target', $cycle->exit_reason);
        $this->assertSame([], app(OrderReconciler::class)->reconcile());
    }

    public function test_with_the_general_halt_on_the_orders_are_still_read_and_reconciled()
    {
        $this->candles($this->asset);
        $sellState = 'NEW';
        $portfolio = $this->liveMicro($sellState);
        app(MicroTrader::class)->run($portfolio);
        app(TradingHalt::class)->halt('reconciler', 'teste');

        // Reading the exchange is not sending: no "Parada geral acionada" divergence that halts again.
        $this->assertSame([], app(OrderReconciler::class)->reconcile());

        // A resting sale that executed during the halt is still booked.
        $sellState = 'FILLED';
        app(PositionExits::class)->checkStopsAndTargets();
        $limit = Order::where('type', OrderType::Limit)->sole();
        $this->assertSame(OrderStatus::Filled, $limit->status);
        $this->assertEqualsWithDelta(0.1997, $limit->fills()->sum('qty'), 1e-8);
        $this->assertSame([], app(OrderReconciler::class)->reconcile());
    }

    public function test_the_account_sync_does_not_close_a_position_with_a_resting_sale()
    {
        $this->candles($this->asset);
        $sellState = 'NEW';
        $portfolio = $this->liveMicro($sellState);
        app(MicroTrader::class)->run($portfolio);

        // Even if the account read misses the coin, the position of the resting sale stays open.
        Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v3/account' => Http::response(['balances' => [['asset' => 'USDT', 'free' => '30', 'locked' => '0']]]),
            '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
            default => Http::response([]),
        });
        app(BinanceAccountSync::class)->sync($portfolio);

        $this->assertNull(MicroCycle::sole()->position->fresh()->closed_at);
        $this->assertSame('open', MicroCycle::sole()->status);
    }

    public function test_a_real_stop_cancels_the_limit_sale_on_binance_first()
    {
        $this->candles($this->asset);
        $sellState = 'NEW';
        $portfolio = $this->liveMicro($sellState);
        app(MicroTrader::class)->run($portfolio);

        $this->advanceTo(99.0);
        app(PositionExits::class)->checkStopsAndTargets();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'orderId=2'));
        $this->assertSame(OrderStatus::Cancelled, Order::where('type', OrderType::Limit)->sole()->status);
        $this->assertSame('stop', Order::where('side', OrderSide::Sell)->where('type', OrderType::Market)->sole()->reason);
    }

    public function test_owner_saves_the_settings_and_the_page_shows_the_mode()
    {
        $portfolio = $this->micro(['micro_enabled' => false]);
        $owner = $portfolio->user;

        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), [
            'micro_enabled' => '1',
            'micro_order_value' => '25',
            'micro_net_margin_pct' => '0.3',
            'micro_daily_target' => '2',
            'micro_daily_loss_limit' => '1',
        ])->assertRedirect();

        $portfolio->refresh();
        $this->assertTrue($portfolio->micro_enabled);
        $this->assertEquals(25, $portfolio->micro_order_value);
        $this->assertEquals(2, $portfolio->micro_daily_target);
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.micro_settings', 'subject_id' => $portfolio->id]);

        $this->actingAs($owner)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.enabled', true)
            ->where('micro.gross_margin_pct', 0.6)
            ->where('micro.state.code', 'active'));
    }

    public function test_validation_and_limits_on_the_settings()
    {
        $portfolio = $this->micro(['micro_enabled' => false]);
        $owner = $portfolio->user;

        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), ['micro_enabled' => '1', 'micro_order_value' => '0.5', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasErrors('micro_order_value');

        // A simulated portfolio accepts 1 dollar per operation; a real one needs the 5 USDT Binance minimum.
        $this->actingAs($owner)->put(route('portfolios.micro', $portfolio), ['micro_enabled' => '0', 'micro_order_value' => '1', 'micro_net_margin_pct' => '0.2', 'micro_daily_operations' => '20', 'micro_wallet_pct' => '60'])
            ->assertSessionHasNoErrors();
        $this->assertSame(20, $portfolio->fresh()->micro_daily_operations);
        $this->assertSame(60, $portfolio->fresh()->micro_wallet_pct);

        $real = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($real->user)->put(route('portfolios.micro', $real), ['micro_enabled' => '0', 'micro_order_value' => '1', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasErrors('micro_order_value');
        $this->actingAs($real->user)->put(route('portfolios.micro', $real), ['micro_enabled' => '0', 'micro_order_value' => '5', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasNoErrors();

        $b3 = Portfolio::factory()->create(['market' => Market::B3]);
        $this->actingAs($b3->user)->put(route('portfolios.micro', $b3), ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasErrors('micro_enabled');

        $live = Portfolio::factory()->create(['mode' => 'live', 'broker' => 'binance']);
        $this->actingAs($live->user)->put(route('portfolios.micro', $live), ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2'])
            ->assertSessionHasErrors('micro_enabled');
        $this->assertFalse($live->fresh()->micro_enabled);
    }

    public function test_other_users_get_403_on_the_settings()
    {
        $portfolio = $this->micro(['micro_enabled' => false]);

        $this->actingAs(User::factory()->create())->put(route('portfolios.micro', $portfolio), ['micro_enabled' => '1', 'micro_order_value' => '20', 'micro_net_margin_pct' => '0.2'])
            ->assertForbidden();
        $this->assertFalse($portfolio->fresh()->micro_enabled);
    }

    private function boughtPosition(Portfolio $portfolio, float $qty = 0.2, float $paid = 100.0): Position
    {
        Order::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'broker' => $portfolio->broker, 'side' => OrderSide::Buy, 'type' => 'market', 'qty' => $qty, 'price' => $paid, 'status' => OrderStatus::Filled, 'fees' => 0, 'filled_at' => now()->subHour()]);

        // Recreated by the account sync: the average is the price of that moment, not what was paid.
        return Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'qty' => $qty, 'avg_price' => 90, 'opened_at' => now()]);
    }

    public function test_a_pair_marked_to_liquidate_is_not_bought()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro(['micro_liquidate_asset_ids' => [$this->asset->id]]);

        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
        $this->assertSame(0, Order::count());
        $this->assertStringContainsString('liquidar com lucro', (string) app(MicroTrader::class)->gate($portfolio, $this->asset, app(MicroRanker::class)->rank($portfolio, 0.5)->first()['setup'], 0));
    }

    public function test_a_marked_position_is_sold_only_with_net_profit_even_with_the_mode_off()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro(['micro_enabled' => false, 'micro_liquidate_asset_ids' => [$this->asset->id]]);
        $position = $this->boughtPosition($portfolio);

        // Paid 100: 100.15 does not cover the 0.30% round trip (sells from 100.30).
        $this->artisan('micro:run')->assertSuccessful();
        $this->assertNull($position->fresh()->closed_at);
        $this->assertSame(1, Order::count());

        $this->advanceTo(100.5);
        $this->artisan('micro:run')->assertSuccessful();

        $position->refresh();
        $this->assertNotNull($position->closed_at);
        $this->assertSame('micro_liquidate', $position->exit_reason);
        $sell = Order::where('side', OrderSide::Sell)->sole();
        $this->assertSame(OrderStatus::Filled, $sell->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.liquidated', 'subject_id' => $position->id]);
        // Still marked: nothing is bought back.
        $this->assertSame(0, MicroCycle::count());
    }

    public function test_an_open_cycle_of_a_marked_pair_closes_as_liquidated()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        $portfolio->update(['micro_liquidate_asset_ids' => [$this->asset->id]]);

        $cycle = MicroCycle::sole();
        $this->advanceTo($cycle->entry_price * 1.004);
        app(MicroTrader::class)->run($portfolio->fresh());

        $cycle->refresh();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('micro_liquidate', $cycle->exit_reason);
        $this->assertGreaterThan(0, $cycle->net_pnl);
    }

    public function test_a_real_sale_uses_the_free_balance_rounded_down_to_the_step()
    {
        config(['trading.live.enabled' => true]);
        $this->asset->update(['lot_size' => '0.0001', 'min_qty' => '0.0001', 'min_notional' => '5']);
        $this->candles($this->asset);
        $this->advanceTo(100.5);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);
        $portfolio = $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id, 'auto_execute' => true, 'live_auto_confirmed_at' => now(), 'micro_enabled' => false, 'micro_liquidate_asset_ids' => [$this->asset->id], 'cash' => 50, 'initial_cash' => 50]);
        // Bought 0.188, the exchange kept the fee in DOGE: 0.18781 on the account.
        $position = $this->boughtPosition($portfolio, 0.188);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
                $path === '/api/v3/account' => Http::response(['balances' => [['asset' => 'DOGE', 'free' => '0.18781', 'locked' => '0'], ['asset' => 'USDT', 'free' => '50', 'locked' => '0']]]),
                default => Http::response(['orderId' => 9, 'status' => 'FILLED', 'executedQty' => '0.1878', 'cummulativeQuoteQty' => '18.8739', 'transactTime' => now()->getTimestampMs(), 'fills' => [['price' => '100.5', 'qty' => '0.1878', 'commission' => '0.0188', 'commissionAsset' => 'USDT']]]),
            };
        });

        $this->assertSame(1, app(MicroTrader::class)->liquidate($portfolio));

        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'side=SELL') && str_contains($request->url(), 'quantity=0.1878&'));
        // The 0.0002 left (0.02 USDT) cannot be sold: the position is over.
        $this->assertNotNull($position->fresh()->closed_at);
    }

    public function test_a_real_portfolio_without_confirmo_does_not_liquidate()
    {
        $this->candles($this->asset);
        $this->advanceTo(110);
        $portfolio = $this->micro(['mode' => 'live', 'broker' => 'binance', 'micro_enabled' => false, 'micro_liquidate_asset_ids' => [$this->asset->id]]);
        $this->boughtPosition($portfolio);

        $this->assertSame(0, app(MicroTrader::class)->liquidate($portfolio));
        Http::assertNothingSent();
    }

    public function test_owner_marks_an_asset_and_the_card_shows_it_and_other_users_get_403()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro(['micro_enabled' => false]);
        $this->boughtPosition($portfolio);

        $this->actingAs(User::factory()->create())->put(route('portfolios.liquidate', $portfolio), ['asset_id' => $this->asset->id, 'liquidate' => '1'])
            ->assertForbidden();
        $this->assertNull($portfolio->fresh()->micro_liquidate_asset_ids);

        $this->actingAs($portfolio->user)->put(route('portfolios.liquidate', $portfolio), ['asset_id' => $this->asset->id, 'liquidate' => '1'])
            ->assertRedirect();
        $this->assertSame([$this->asset->id], $portfolio->fresh()->micro_liquidate_asset_ids);
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.micro_liquidate', 'subject_id' => $portfolio->id]);

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.liquidation.0.symbol', 'DOGEUSDT')
            ->where('micro.liquidation.0.marked', true)
            ->where('micro.liquidation.0.cost', 100)
            ->where('micro.liquidation.0.sell_from', 100.3));

        $this->actingAs($portfolio->user)->put(route('portfolios.liquidate', $portfolio), ['asset_id' => $this->asset->id, 'liquidate' => '0'])
            ->assertRedirect();
        $this->assertNull($portfolio->fresh()->micro_liquidate_asset_ids);
    }

    public function test_an_account_position_gets_a_resting_sale_at_cost_plus_round_trip_plus_margin()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        // Paid 100 (the sync recorded 90): sells at 100 * (1 + 0.30% + 0.20%).
        $position = $this->boughtPosition($portfolio);

        app(MicroTrader::class)->run($portfolio);

        $sell = Order::where('side', OrderSide::Sell)->sole();
        $this->assertSame(OrderType::Limit, $sell->type);
        $this->assertSame(OrderStatus::Pending, $sell->status);
        $this->assertSame($position->id, $sell->position_id);
        $this->assertEqualsWithDelta(100.5, $sell->price, 0.01);

        // Already resting: nothing more is sent the next minute.
        app(MicroTrader::class)->run($portfolio);
        $this->assertSame(1, Order::where('side', OrderSide::Sell)->count());

        $row = collect(app(MicroOverview::class)->for($portfolio)['liquidation'])->firstWhere('symbol', 'DOGEUSDT');
        $this->assertEqualsWithDelta(100.5, $row['resting_sell_price'], 0.01);
        $this->assertNull($row['sell_failure']);
    }

    public function test_positions_from_agent_signals_and_marked_pairs_get_no_resting_sale()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro(['micro_liquidate_asset_ids' => [$this->asset->id]]);
        $this->boughtPosition($portfolio);

        app(MicroTrader::class)->registerSells($portfolio);

        $this->assertSame(0, Order::where('side', OrderSide::Sell)->count());
    }

    public function test_a_refused_real_sale_is_shown_on_the_card_and_tried_again()
    {
        config(['trading.live.enabled' => true]);
        // DUSK-like pair: step 1, minimum 5 USDT.
        $this->asset->update(['lot_size' => '1', 'tick_size' => '0.0001', 'min_qty' => '1', 'min_notional' => '5']);
        $this->candles($this->asset, 100.0);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);
        $portfolio = $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id, 'auto_execute' => true, 'live_auto_confirmed_at' => now(), 'cash' => 50, 'initial_cash' => 50]);
        $position = $this->boughtPosition($portfolio, 0.5);
        $position->update(['qty' => 39.922]);
        $refuse = true;

        Http::fake(function (Request $request) use (&$refuse) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
                $path === '/api/v3/account' => Http::response(['balances' => [['asset' => 'DOGE', 'free' => '39.922', 'locked' => '0'], ['asset' => 'USDT', 'free' => '50', 'locked' => '0']]]),
                $request->method() === 'POST' && $refuse => Http::response(['code' => -2010, 'msg' => 'Account has insufficient balance for requested action.'], 400),
                $request->method() === 'POST' => Http::response(['orderId' => 7, 'status' => 'NEW', 'executedQty' => '0', 'cummulativeQuoteQty' => '0', 'fills' => []]),
                default => Http::response([]),
            };
        });

        app(MicroTrader::class)->registerSells($portfolio);

        $this->assertSame(OrderStatus::Rejected, Order::where('side', OrderSide::Sell)->sole()->status);
        $row = collect(app(MicroOverview::class)->for($portfolio)['liquidation'])->firstWhere('symbol', 'DOGEUSDT');
        $this->assertStringContainsString('Saldo insuficiente', $row['sell_failure']['reason']);
        $this->assertNull($row['resting_sell_price']);

        // Each minute the quote stays fresh, as the market collector keeps it.
        $minute = function () {
            $this->travel(61)->seconds();
            Candle::where('asset_id', $this->asset->id)->latest('open_at')->first()->update(['open_at' => now()->startOfMinute()]);
            app(MicroTrader::class)->registerSells(Portfolio::sole());
        };

        // Refused again a minute later, then spaced: two minutes until the third try.
        $minute();
        $this->assertSame(2, Order::where('side', OrderSide::Sell)->count());
        $minute();
        $this->assertSame(2, Order::where('side', OrderSide::Sell)->count());

        $refuse = false;
        $minute();

        $resting = Order::where('side', OrderSide::Sell)->where('status', OrderStatus::Pending)->sole();
        // Whole coins only (step 1), at the cost of 100 plus 0.50%.
        $this->assertEqualsWithDelta(39.0, $resting->qty, 1e-8);
        $this->assertEqualsWithDelta(100.5, $resting->price, 0.001);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'quantity=39&') && str_contains($request->url(), 'type=LIMIT'));
        $this->assertNull(app(MicroTrader::class)->sellFailure($position));
    }

    public function test_the_risk_guard_cuts_a_sale_at_its_own_position_and_on_the_pair_step()
    {
        $this->candles($this->asset);
        $this->asset->update(['lot_size' => '1']);
        $portfolio = $this->micro();
        // Two open positions of the pair (as the #108 sync bug left): the sale names the second one.
        Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'qty' => 38.961, 'avg_price' => 100, 'opened_at' => now()]);
        $own = Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'qty' => 41.5, 'avg_price' => 100, 'opened_at' => now()]);

        $decision = app(RiskGuard::class)->check($portfolio, new OrderRequest(asset: $this->asset->fresh(), side: OrderSide::Sell, qty: 45, type: OrderType::Limit, limitPrice: 101, positionId: $own->id));

        $this->assertTrue($decision->allowed);
        $this->assertSame(41.0, $decision->order->qty);

        // Without a position the cut still lands on the step.
        $decision = app(RiskGuard::class)->check($portfolio, new OrderRequest(asset: $this->asset->fresh(), side: OrderSide::Sell, qty: 45));
        $this->assertSame(38.0, $decision->order->qty);
    }

    public function test_a_cycle_whose_coins_left_the_account_closes_without_a_made_up_result()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        $cycle = MicroCycle::sole();
        Order::where('side', OrderSide::Sell)->update(['status' => OrderStatus::Cancelled]);
        $cycle->position->update(['closed_at' => now(), 'exit_reason' => 'conta']);

        app(MicroTrader::class)->settle($portfolio);

        $cycle->refresh();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('conta', $cycle->exit_reason);
        $this->assertNull($cycle->exit_price);
        $this->assertNull($cycle->net_pnl);
    }

    public function test_other_users_get_403_on_the_portfolio_with_the_sale_rows()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $this->boughtPosition($portfolio);
        app(MicroTrader::class)->run($portfolio);

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();
        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.liquidation.0.resting_sell_price', fn ($price) => abs($price - 100.5) < 0.01));
    }

    public function test_the_odds_count_how_often_the_pair_reached_the_distance_within_the_window()
    {
        $rows = [];

        // 600 minutes at 100 with a 0.06% range; every 10th minute the high touches +0.6%.
        for ($i = 0; $i < 600; $i++) {
            $rows[] = ['asset_id' => $this->asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute()->subMinutes(599 - $i), 'open' => 100, 'high' => $i % 10 === 0 ? 100.6 : 100.03, 'low' => 99.97, 'close' => 100, 'volume' => 1];
        }

        Candle::insert($rows);
        $odds = app(MicroTargetOdds::class);

        $near = $odds->estimate($this->asset, 100, 100.5, 10);
        $this->assertSame(100, $near['pct']);
        $this->assertSame(0.5, $near['distance_pct']);
        $this->assertStringContainsString('subiu 0,50% em até 10 min em 100% das vezes', $near['reason']);

        $this->assertSame(50, $odds->estimate($this->asset, 100, 100.5, 5)['pct']);
        $this->assertSame(0, $odds->estimate($this->asset, 100, 102, 30)['pct']);
        $this->assertSame(100, $odds->estimate($this->asset, 101, 100.5, 30)['pct']);
        $this->assertNull($odds->estimate(Asset::factory()->create(), 1, 1.01, 30)['pct']);
    }

    public function test_the_card_shows_the_odds_of_reaching_the_resting_sale()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $this->boughtPosition($portfolio);
        app(MicroTrader::class)->run($portfolio);

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.liquidation.0.odds.target', fn ($target) => abs($target - 100.5) < 0.01)
            ->where('micro.liquidation.0.odds.minutes', 60)
            // Two hours of candles are too few to compare against a 60-minute window.
            ->where('micro.liquidation.0.odds.pct', null)
            ->where('micro.liquidation.0.odds.reason', fn ($reason) => str_contains($reason, 'Poucas velas'))
            ->where('micro.sell_fee_rate', (float) config('trading.costs.crypto.fee_rate')));
    }

    public function test_liquidar_agora_cancels_the_resting_sale_and_sells_at_a_loss_and_other_users_get_403()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $position = $this->boughtPosition($portfolio);
        app(MicroTrader::class)->run($portfolio);
        $resting = Order::where('status', OrderStatus::Pending)->where('side', OrderSide::Sell)->firstOrFail();
        $this->advanceTo(99);

        $this->actingAs(User::factory()->create())->post(route('portfolios.liquidate-now', $portfolio), ['asset_id' => $this->asset->id])
            ->assertForbidden();
        $this->assertNull($position->fresh()->closed_at);
        $this->assertSame(OrderStatus::Pending, $resting->fresh()->status);

        $this->actingAs($portfolio->user)->post(route('portfolios.liquidate-now', $portfolio), ['asset_id' => $this->asset->id])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled, $resting->fresh()->status);
        $position->refresh();
        $this->assertNotNull($position->closed_at);
        $this->assertSame('micro_manual', $position->exit_reason);
        $sale = Order::where('reason', 'micro_manual')->firstOrFail();
        $this->assertSame(OrderStatus::Filled, $sale->status);
        $this->assertSame($position->id, $sale->position_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'micro.liquidated_now', 'subject_id' => $position->id]);
        $log = AuditLog::where('action', 'micro.liquidated_now')->firstOrFail();
        $this->assertLessThan(0, $log->payload['net_pnl']);
    }

    public function test_liquidar_agora_closes_the_open_cycle_as_sold_by_hand()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);
        $cycle = MicroCycle::firstOrFail();
        $this->advanceTo(99.9);

        $this->actingAs($portfolio->user)->post(route('portfolios.liquidate-now', $portfolio), ['asset_id' => $this->asset->id])
            ->assertRedirect();

        $cycle->refresh();
        $this->assertSame('closed', $cycle->status);
        $this->assertSame('micro_manual', $cycle->exit_reason);
        $this->assertLessThan(0, $cycle->net_pnl);
    }

    public function test_liquidar_agora_without_a_position_or_under_the_pair_minimum_sends_nothing()
    {
        config(['trading.live.enabled' => true]);
        $this->asset->update(['lot_size' => '0.0001', 'min_qty' => '0.0001', 'min_notional' => '5']);
        $this->candles($this->asset);
        $portfolio = $this->micro(['mode' => 'live', 'broker' => 'binance', 'micro_enabled' => false]);

        $this->actingAs($portfolio->user)->post(route('portfolios.liquidate-now', $portfolio), ['asset_id' => $this->asset->id])
            ->assertRedirect();

        $position = $this->boughtPosition($portfolio, 0.04);
        $outcome = app(MicroTrader::class)->liquidateNow($portfolio, $position, 'user:1');

        $this->assertIsString($outcome);
        $this->assertStringContainsString('abaixo do mínimo de 5.00 USDT', $outcome);
        $this->assertNull($position->fresh()->closed_at);
        Http::assertNothingSent();
    }

    /**
     * A real DUSK-like position (step 1) of 39.922 coins, a resting limit sale
     * of it on the exchange (order 2), and a fake Binance that fills market sales.
     */
    private function liveStepOnePosition(string $minNotional = '5'): Position
    {
        config(['trading.live.enabled' => true]);
        $this->asset->update(['lot_size' => '1', 'tick_size' => '0.0001', 'min_qty' => '1', 'min_notional' => $minNotional]);
        $this->candles($this->asset, 100.0);
        $user = User::factory()->create();
        $credential = BrokerCredential::create(['user_id' => $user->id, 'broker' => 'binance', 'label' => 'x', 'api_key' => 'k', 'api_secret' => 's', 'can_withdraw' => false, 'verified_at' => now()]);
        $portfolio = $this->micro(['user_id' => $user->id, 'mode' => 'live', 'broker' => 'binance', 'broker_credential_id' => $credential->id, 'auto_execute' => true, 'live_auto_confirmed_at' => now(), 'cash' => 50, 'initial_cash' => 50]);
        $position = $this->boughtPosition($portfolio, 0.5);
        $position->update(['qty' => 39.922]);
        Order::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'position_id' => $position->id, 'broker' => 'binance', 'side' => OrderSide::Sell, 'type' => OrderType::Limit, 'qty' => 39, 'price' => 100.5, 'status' => OrderStatus::Pending, 'external_id' => 'DOGEUSDT:2']);

        $state = 'NEW';

        Http::fake(function (Request $request) use (&$state) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/v3/time' => Http::response(['serverTime' => (int) (microtime(true) * 1000)]),
                $path === '/api/v3/account' => Http::response(['balances' => [['asset' => 'DOGE', 'free' => '39.922', 'locked' => '0'], ['asset' => 'USDT', 'free' => '50', 'locked' => '0']]]),
                $request->method() === 'DELETE' => Http::response(['orderId' => 2, 'status' => $state = 'CANCELED']),
                $request->method() === 'POST' => Http::response(['orderId' => 9, 'status' => 'FILLED', 'executedQty' => '39', 'cummulativeQuoteQty' => '3900', 'transactTime' => now()->getTimestampMs(), 'fills' => [['price' => '100', 'qty' => '39', 'commission' => '3.9', 'commissionAsset' => 'USDT']]]),
                default => Http::response(['orderId' => 2, 'status' => $state, 'executedQty' => '0', 'cummulativeQuoteQty' => '0']),
            };
        });

        return $position;
    }

    private function sellSignal(Position $position): Signal
    {
        return Signal::create(['portfolio_id' => $position->portfolio_id, 'asset_id' => $position->asset_id, 'stage' => SignalStage::Confirmed, 'side' => 'sell', 'decided_by' => 'auto']);
    }

    public function test_a_sell_signal_cancels_the_resting_sale_and_sells_whole_steps_of_its_position()
    {
        $position = $this->liveStepOnePosition();

        $order = app(OrderExecutor::class)->execute($this->sellSignal($position), 'auto');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'orderId=2'));
        // 39.922 on the account, step 1: 39 is what the exchange accepts (39.922 was refused with LOT_SIZE).
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'type=MARKET') && str_contains($request->url(), 'quantity=39&'));
        $this->assertSame(OrderStatus::Filled, $order->status);
        $this->assertSame($position->id, $order->position_id);
        $this->assertSame(OrderStatus::Cancelled, Order::where('external_id', 'DOGEUSDT:2')->sole()->status);
    }

    public function test_a_sell_signal_under_the_pair_minimum_is_refused_without_touching_the_exchange()
    {
        // 39 whole coins at 100 = 3900, under a 5000 minimum (as 39 DUSK at 0.1201 = 4.68 USDT under 5).
        $position = $this->liveStepOnePosition('5000');
        $signal = $this->sellSignal($position);

        $this->assertNull(app(OrderExecutor::class)->execute($signal, 'auto'));

        $this->assertSame(SignalStage::Rejected, $signal->fresh()->stage);
        $this->assertStringContainsString('abaixo do mínimo', (string) $signal->fresh()->decision_reason);
        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'DELETE'], true));
        $this->assertSame(OrderStatus::Pending, Order::where('external_id', 'DOGEUSDT:2')->sole()->status);
    }

    public function test_liquidate_now_counts_only_whole_steps_against_the_minimum_and_keeps_the_resting_sale()
    {
        // 39.922 x 100 = 3992 passes a 3950 minimum, but only 39 coins can go: 3900.
        $position = $this->liveStepOnePosition('3950');

        $outcome = app(MicroTrader::class)->liquidateNow($position->portfolio, $position, 'user:1');

        $this->assertIsString($outcome);
        $this->assertStringContainsString('abaixo do mínimo', $outcome);
        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'DELETE'], true));
        $this->assertSame(OrderStatus::Pending, Order::where('external_id', 'DOGEUSDT:2')->sole()->status);
    }

    public function test_the_card_says_why_a_position_has_no_registered_sale()
    {
        $this->candles($this->asset);
        $portfolio = $this->micro();
        $this->boughtPosition($portfolio);
        app(TradingHalt::class)->halt('reconciler', 'teste');

        app(MicroTrader::class)->run($portfolio);

        $this->assertSame(0, Order::where('side', OrderSide::Sell)->count());
        $row = collect(app(MicroOverview::class)->for($portfolio)['liquidation'])->firstWhere('symbol', 'DOGEUSDT');
        $this->assertStringContainsString('Parada geral ligada', $row['sell_skip']);

        $other = Asset::factory()->create(['symbol' => 'SOLUSDT']);
        $portfolio->assets()->attach($other->id);
        app(TradingHalt::class)->resume('teste');
        $this->assertStringContainsString('Ativos acompanhados', app(MicroTrader::class)->sellSkip($portfolio->fresh(), $portfolio->openPositions()->sole()));
    }

    public function test_the_risk_guard_keeps_every_sale_on_the_pair_step()
    {
        $this->candles($this->asset);
        $this->asset->update(['lot_size' => '1']);
        $portfolio = $this->micro();
        $position = Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'qty' => 39.922, 'avg_price' => 100, 'opened_at' => now()]);

        // Within the position but off the step: 39.922 goes out as 39.
        $decision = app(RiskGuard::class)->check($portfolio, new OrderRequest(asset: $this->asset->fresh(), side: OrderSide::Sell, qty: 39.922, positionId: $position->id));

        $this->assertTrue($decision->allowed);
        $this->assertSame(39.0, $decision->order->qty);
    }
}
