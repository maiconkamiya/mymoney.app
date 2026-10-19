<?php

namespace Tests\Feature\Trading;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\SignalScanner;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\PortfolioMode;
use App\Enums\SignalStage;
use App\Events\LiveOrderUpdated;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\BrokerCredential;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Models\User;
use App\Notifications\LiveOrderNotification;
use App\Trading\Live\PanicStop;
use App\Trading\OrderExecutor;
use App\Trading\TradingHalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsMarket;
use Tests\Concerns\ProvidesAgentInputs;
use Tests\TestCase;

class LiveAutoExecutionTest extends TestCase
{
    use BuildsMarket, ProvidesAgentInputs, RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trading.live.enabled' => true, 'trading.live.max_order_value' => 20, 'trading.live.max_daily_value' => 30]);
        Http::preventStrayRequests();

        $this->asset = Asset::factory()->create(['symbol' => 'BTCUSDT', 'lot_size' => '0.00001']);
        $this->quote($this->asset, 100);
    }

    private function credential(?User $user = null, array $attributes = []): BrokerCredential
    {
        return BrokerCredential::create($attributes + [
            'user_id' => ($user ?? User::factory()->create())->id,
            'broker' => 'binance',
            'label' => 'teste',
            'api_key' => 'key',
            'api_secret' => 'secret',
            'can_withdraw' => false,
            'verified_at' => now(),
        ]);
    }

    private function livePortfolio(array $attributes = [], ?BrokerCredential $credential = null): Portfolio
    {
        $credential ??= $this->credential();

        return Portfolio::factory()->create($attributes + [
            'user_id' => $credential->user_id,
            'mode' => PortfolioMode::Live,
            'broker' => 'binance',
            'broker_credential_id' => $credential->id,
            'initial_cash' => 1_000,
            'cash' => 1_000,
        ]);
    }

    private function autoPortfolio(array $attributes = []): Portfolio
    {
        return $this->livePortfolio($attributes + ['auto_execute' => true, 'live_auto_confirmed_at' => now()]);
    }

    private function autoSignal(Portfolio $portfolio, string $side = 'buy', string $decidedBy = 'auto'): Signal
    {
        return Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $this->asset->id,
            'stage' => SignalStage::Confirmed,
            'side' => $side,
            'stop_price' => 95,
            'score' => 0.62,
            'calculation' => ['threshold' => 0.35, 'votes' => []],
            'decided_by' => $decidedBy,
        ]);
    }

    /** @param  list<array<string, mixed>>  $open */
    private function fakeBinance(array $open = [], ?\Closure $order = null): void
    {
        Http::fake(function (Request $request) use ($open, $order) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/api/v3/time') {
                return Http::response(['serverTime' => (int) (microtime(true) * 1000)]);
            }

            if ($path === '/api/v3/openOrders') {
                return Http::response($open);
            }

            if ($path === '/api/v3/account') {
                return Http::response(['balances' => [['asset' => 'BTC', 'free' => '0.05', 'locked' => '0'], ['asset' => 'USDT', 'free' => '100', 'locked' => '0']]]);
            }

            if ($request->method() === 'DELETE') {
                return Http::response(['status' => 'CANCELED']);
            }

            return $order !== null ? $order($request) : Http::response([
                'orderId' => 77, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20',
                'transactTime' => now()->getTimestampMs(),
                'fills' => [['price' => '100', 'qty' => '0.2', 'commission' => '0.02', 'commissionAsset' => 'USDT']],
            ]);
        });
    }

    private function sentOrders(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST')->count();
    }

    // --- Ligar o toggle ---------------------------------------------------

    public function test_owner_turns_real_auto_execute_on_with_confirmo()
    {
        $portfolio = $this->livePortfolio(['orders_blocked_at' => now(), 'orders_blocked_reason' => 'antes']);

        $this->actingAs($portfolio->user)
            ->patch(route('portfolios.update', $portfolio), ['auto_execute' => '1', 'confirmacao' => 'CONFIRMO'])
            ->assertSessionHasNoErrors();

        $portfolio->refresh();
        $this->assertTrue($portfolio->auto_execute);
        $this->assertNotNull($portfolio->live_auto_confirmed_at);
        $this->assertNull($portfolio->orders_blocked_at);
        $this->assertTrue($portfolio->autoExecutesLive());
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.auto_execute', 'subject_id' => $portfolio->id, 'actor' => 'user:'.$portfolio->user_id]);
    }

    public function test_real_auto_execute_needs_the_typed_confirmation()
    {
        $portfolio = $this->livePortfolio();

        foreach ([null, '', 'confirmo', 'sim'] as $typed) {
            $this->actingAs($portfolio->user)
                ->patch(route('portfolios.update', $portfolio), ['auto_execute' => '1', 'confirmacao' => $typed])
                ->assertSessionHasErrors('confirmacao');
        }

        $this->assertFalse($portfolio->fresh()->auto_execute);
        $this->assertNull($portfolio->fresh()->live_auto_confirmed_at);
    }

    public function test_real_auto_execute_needs_the_live_switch()
    {
        config(['trading.live.enabled' => false]);
        $portfolio = $this->livePortfolio();

        $this->actingAs($portfolio->user)
            ->patch(route('portfolios.update', $portfolio), ['auto_execute' => '1', 'confirmacao' => 'CONFIRMO'])
            ->assertSessionHasErrors('auto_execute');

        $this->assertFalse($portfolio->fresh()->auto_execute);
    }

    public function test_real_auto_execute_is_refused_during_a_general_halt_or_with_an_unsafe_key()
    {
        $portfolio = $this->livePortfolio();
        app(TradingHalt::class)->halt('teste');

        $this->actingAs($portfolio->user)
            ->patch(route('portfolios.update', $portfolio), ['auto_execute' => '1', 'confirmacao' => 'CONFIRMO'])
            ->assertSessionHasErrors('auto_execute');

        app(TradingHalt::class)->resume('teste');
        $unsafe = $this->livePortfolio([], $this->credential(attributes: ['can_withdraw' => true]));

        $this->actingAs($unsafe->user)
            ->patch(route('portfolios.update', $unsafe), ['auto_execute' => '1', 'confirmacao' => 'CONFIRMO'])
            ->assertSessionHasErrors('auto_execute');
        $this->assertFalse($unsafe->fresh()->auto_execute);
    }

    public function test_turning_real_auto_execute_off_clears_the_confirmation_without_typing()
    {
        $portfolio = $this->autoPortfolio();

        $this->actingAs($portfolio->user)
            ->patch(route('portfolios.update', $portfolio), ['auto_execute' => '0'])
            ->assertSessionHasNoErrors();

        $portfolio->refresh();
        $this->assertFalse($portfolio->auto_execute);
        $this->assertNull($portfolio->live_auto_confirmed_at);
    }

    public function test_email_notice_is_saved_without_changing_automatic_execution()
    {
        $portfolio = $this->autoPortfolio();

        $this->actingAs($portfolio->user)
            ->patch(route('portfolios.update', $portfolio), ['auto_execute' => '1', 'notify_live_email' => '1'])
            ->assertSessionHasNoErrors();

        $portfolio->refresh();
        $this->assertTrue($portfolio->notify_live_email);
        $this->assertTrue($portfolio->autoExecutesLive());
    }

    public function test_other_accounts_get_403_on_update_panic_and_release()
    {
        $portfolio = $this->autoPortfolio();
        $stranger = User::factory()->create();
        $this->fakeBinance();

        $this->actingAs($stranger)->patch(route('portfolios.update', $portfolio), ['auto_execute' => '0'])->assertForbidden();
        $this->actingAs($stranger)->post(route('portfolios.panic', $portfolio))->assertForbidden();
        $this->actingAs($stranger)->delete(route('portfolios.release', $portfolio))->assertForbidden();
        $this->actingAs($stranger)->get(route('portfolios.show', $portfolio))->assertForbidden();

        $this->assertTrue($portfolio->fresh()->auto_execute);
        $this->assertNull($portfolio->fresh()->orders_blocked_at);
        Http::assertNothingSent();
    }

    // --- Execução automática ---------------------------------------------

    public function test_auto_signal_sends_a_capped_idempotent_order_and_audits_the_calculation()
    {
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio();
        $signal = $this->autoSignal($portfolio);

        $order = app(OrderExecutor::class)->execute($signal, 'auto');

        $this->assertSame(OrderStatus::Filled, $order->status, (string) $order->reject_reason);
        $this->assertEqualsWithDelta(0.2, $order->qty, 1e-9); // teto de 20 por ordem a 100
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'newClientOrderId=signal-'.$signal->id.'&'));

        $audit = AuditLog::query()->where('action', 'order.filled')->sole();
        $this->assertSame('auto', $audit->actor);
        $this->assertSame($signal->id, $audit->payload['signal']['id']);
        $this->assertEqualsWithDelta(0.62, $audit->payload['signal']['score'], 1e-9);
        $this->assertSame(0.35, $audit->payload['signal']['calculation']['threshold']);
    }

    public function test_the_same_signal_never_sends_two_real_orders()
    {
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio();
        $signal = $this->autoSignal($portfolio);

        $first = app(OrderExecutor::class)->execute($signal, 'auto');
        // Even if the signal is somehow confirmed again (retry, double run), the first order is the only one.
        $signal->fresh()->update(['stage' => SignalStage::Confirmed]);
        $second = app(OrderExecutor::class)->execute($signal->fresh(), 'auto');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $this->sentOrders());
        $this->assertSame(1, Order::count());
    }

    public function test_auto_signal_without_the_owners_confirmation_never_reaches_the_broker()
    {
        $this->fakeBinance();
        $portfolio = $this->livePortfolio(['auto_execute' => true]); // ligada à força, sem CONFIRMO

        $order = app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto');

        $this->assertNull($order);
        Http::assertNothingSent();
    }

    public function test_auto_orders_stop_when_the_live_switch_is_turned_off()
    {
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio();
        config(['trading.live.enabled' => false]);

        $this->assertNull(app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto'));
        Http::assertNothingSent();
    }

    public function test_daily_cap_applies_to_automatic_orders()
    {
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio();

        app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto'); // 20 de 30
        $second = app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto');
        $this->assertEqualsWithDelta(0.1, $second->qty, 1e-9); // sobram 10

        $this->assertNull(app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto'));
        $this->assertSame(2, $this->sentOrders());
    }

    public function test_stale_quote_blocks_automatic_orders()
    {
        $this->fakeBinance();
        $asset = Asset::factory()->create(['symbol' => 'ETHUSDT']);
        $this->quote($asset, 100, now()->subMinutes(5));
        $portfolio = $this->autoPortfolio();
        $signal = Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Confirmed, 'side' => 'buy', 'stop_price' => 95, 'decided_by' => 'auto']);

        $this->assertNull(app(OrderExecutor::class)->execute($signal, 'auto'));
        $this->assertStringContainsString('atrasada', (string) $signal->fresh()->decision_reason);
        Http::assertNothingSent();
    }

    public function test_short_selling_is_blocked_on_automatic_orders()
    {
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio();

        $this->assertNull(app(OrderExecutor::class)->execute($this->autoSignal($portfolio, 'sell'), 'auto'));
        Http::assertNothingSent();
    }

    public function test_automatic_sell_is_limited_to_the_open_position()
    {
        $this->fakeBinance(order: fn () => Http::response([
            'orderId' => 78, 'status' => 'FILLED', 'executedQty' => '0.05', 'cummulativeQuoteQty' => '5',
            'transactTime' => now()->getTimestampMs(), 'fills' => [],
        ]));
        $portfolio = $this->autoPortfolio();
        Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'qty' => 0.05, 'avg_price' => 90, 'opened_at' => now()]);

        $order = app(OrderExecutor::class)->execute($this->autoSignal($portfolio, 'sell'), 'auto');

        $this->assertEqualsWithDelta(0.05, $order->qty, 1e-9);
    }

    public function test_insufficient_cash_blocks_the_automatic_buy()
    {
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio(['cash' => 0]);

        $this->assertNull(app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto'));
        Http::assertNothingSent();
    }

    public function test_assets_from_another_market_are_refused()
    {
        $this->fakeBinance();
        $b3 = Asset::factory()->create(['symbol' => 'PETR4', 'market' => 'b3']);
        $this->quote($b3, 30);
        $portfolio = $this->autoPortfolio();
        $signal = Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $b3->id, 'stage' => SignalStage::Confirmed, 'side' => 'buy', 'stop_price' => 28, 'decided_by' => 'auto']);

        $this->assertNull(app(OrderExecutor::class)->execute($signal, 'auto'));
        Http::assertNothingSent();
    }

    public function test_binance_errors_are_shown_in_portuguese()
    {
        $this->fakeBinance(order: fn () => Http::response(['code' => -2010, 'msg' => 'Account has insufficient balance for requested action.'], 400));
        $portfolio = $this->autoPortfolio();
        $signal = $this->autoSignal($portfolio);

        $order = app(OrderExecutor::class)->execute($signal, 'auto');

        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertSame('Saldo insuficiente na Binance para esta ordem.', $order->reject_reason);
        $this->assertSame(1, $this->sentOrders()); // erro definitivo: sem retentativa
    }

    public function test_pipeline_sends_the_real_order_when_the_consensus_approves()
    {
        $this->fakeBinance();
        $this->app->instance(LlmClient::class, new FakeLlmClient(fn () => [
            'action' => 'buy', 'confidence' => 0.8, 'veto' => false, 'reasons' => ['teste'], 'evidence_ids' => [], 'stop' => 95.0, 'target' => 110.0,
        ]));
        $this->provideAgentInputs($this->asset);
        $portfolio = $this->autoPortfolio();

        $signals = app(SignalScanner::class)->scan($this->asset);

        $signal = $signals[0]->fresh();
        $this->assertSame(SignalStage::Executed, $signal->stage, (string) $signal->decision_reason);
        $this->assertSame('auto', $signal->decided_by);
        $this->assertSame(1, $this->sentOrders());
        $this->assertSame(1, $portfolio->orders()->count());
    }

    public function test_pipeline_leaves_the_signal_waiting_when_the_portfolio_was_stopped()
    {
        $this->fakeBinance();
        $this->app->instance(LlmClient::class, new FakeLlmClient(fn () => [
            'action' => 'buy', 'confidence' => 0.8, 'veto' => false, 'reasons' => ['teste'], 'evidence_ids' => [], 'stop' => 95.0, 'target' => 110.0,
        ]));
        $this->provideAgentInputs($this->asset);
        $this->autoPortfolio(['orders_blocked_at' => now()]);

        $signal = app(SignalScanner::class)->scan($this->asset)[0]->fresh();

        $this->assertSame(SignalStage::Approved, $signal->stage);
        Http::assertNothingSent();
    }

    // --- Idempotência e retentativa --------------------------------------

    public function test_a_timeout_looks_the_order_up_by_client_id_instead_of_sending_it_again()
    {
        $calls = [];
        $this->fakeBinance(order: function (Request $request) use (&$calls) {
            $calls[] = $request->method();

            return count($calls) === 1
                ? throw new ConnectionException('timeout')
                : Http::response(['orderId' => 99, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20', 'transactTime' => now()->getTimestampMs(), 'fills' => []]);
        });
        $portfolio = $this->autoPortfolio();
        $signal = $this->autoSignal($portfolio);

        $order = app(OrderExecutor::class)->execute($signal, 'auto');

        $this->assertSame(['POST', 'GET'], $calls);
        $this->assertSame(OrderStatus::Filled, $order->status);
        $this->assertSame('BTCUSDT:99', $order->external_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'origClientOrderId=signal-'.$signal->id));
    }

    public function test_a_timeout_for_an_order_the_exchange_never_saw_is_sent_once_more()
    {
        $calls = [];
        $this->fakeBinance(order: function (Request $request) use (&$calls) {
            $calls[] = $request->method();

            return match (count($calls)) {
                1 => throw new ConnectionException('timeout'),
                2 => Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400),
                default => Http::response(['orderId' => 100, 'status' => 'FILLED', 'executedQty' => '0.2', 'cummulativeQuoteQty' => '20', 'transactTime' => now()->getTimestampMs(), 'fills' => []]),
            };
        });
        $portfolio = $this->autoPortfolio();

        $order = app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto');

        $this->assertSame(['POST', 'GET', 'POST'], $calls);
        $this->assertSame(OrderStatus::Filled, $order->status);
        $this->assertFalse($portfolio->fresh()->isOrdersBlocked());
    }

    public function test_an_uncertain_send_stops_the_portfolio_until_a_person_looks()
    {
        $this->fakeBinance(order: fn () => throw new ConnectionException('timeout'));
        $portfolio = $this->autoPortfolio();

        $order = app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto');

        $portfolio->refresh();
        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertTrue($portfolio->isOrdersBlocked());
        $this->assertFalse($portfolio->auto_execute);
        $this->assertNull(app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto'));
    }

    // --- Parar tudo -------------------------------------------------------

    public function test_panic_turns_everything_off_cancels_the_apps_open_orders_and_blocks_new_ones()
    {
        $this->fakeBinance(open: [
            ['symbol' => 'BTCUSDT', 'orderId' => 11, 'clientOrderId' => 'signal-5'],
            ['symbol' => 'BTCUSDT', 'orderId' => 12, 'clientOrderId' => 'position-3-stop'],
            ['symbol' => 'BTCUSDT', 'orderId' => 13, 'clientOrderId' => 'ordem-manual-do-dono'],
        ]);
        $portfolio = $this->autoPortfolio();
        Order::create(['portfolio_id' => $portfolio->id, 'asset_id' => $this->asset->id, 'broker' => 'binance', 'side' => OrderSide::Buy, 'type' => 'limit', 'qty' => 0.1, 'price' => 90, 'status' => OrderStatus::Pending, 'external_id' => 'BTCUSDT:11']);

        $this->actingAs($portfolio->user)->post(route('portfolios.panic', $portfolio))->assertRedirect();

        $portfolio->refresh();
        $this->assertFalse($portfolio->auto_execute);
        $this->assertNull($portfolio->live_auto_confirmed_at);
        $this->assertTrue($portfolio->isOrdersBlocked());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'orderId=11'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'orderId=12'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'orderId=13'));
        $this->assertSame(OrderStatus::Cancelled, Order::where('external_id', 'BTCUSDT:11')->sole()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.panic_stop', 'subject_id' => $portfolio->id]);

        // Depois do pânico nem sinal automático nem aprovação manual mandam ordem.
        $this->assertNull(app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto'));
        $this->assertNull(app(OrderExecutor::class)->execute($this->autoSignal($portfolio, decidedBy: 'user:'.$portfolio->user_id), 'user:'.$portfolio->user_id));
        $this->assertSame(0, $this->sentOrders());
    }

    public function test_panic_blocks_even_when_the_binance_is_down_and_even_with_live_off()
    {
        config(['trading.live.enabled' => false]);
        Http::fake(fn () => throw new ConnectionException('fora do ar'));
        $portfolio = $this->autoPortfolio();

        $result = app(PanicStop::class)->stop($portfolio, 'console');

        $this->assertNotSame([], $result['failed']);
        $this->assertTrue($portfolio->fresh()->isOrdersBlocked());
        $this->assertFalse($portfolio->fresh()->auto_execute);
    }

    public function test_release_lifts_the_block_but_leaves_automatic_execution_off()
    {
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio();
        app(PanicStop::class)->stop($portfolio, 'console');

        $this->actingAs($portfolio->user)->delete(route('portfolios.release', $portfolio))->assertRedirect();

        $portfolio->refresh();
        $this->assertFalse($portfolio->isOrdersBlocked());
        $this->assertFalse($portfolio->auto_execute);
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.orders_released', 'subject_id' => $portfolio->id]);
    }

    public function test_panic_command_stops_one_portfolio_or_all_and_can_release()
    {
        $this->fakeBinance();
        $first = $this->autoPortfolio();
        $second = $this->autoPortfolio();

        $this->artisan('trading:panic', ['portfolio' => $first->id])->assertSuccessful();
        $this->assertTrue($first->fresh()->isOrdersBlocked());
        $this->assertFalse($second->fresh()->isOrdersBlocked());

        $this->artisan('trading:panic', ['--all' => true])->assertSuccessful();
        $this->assertTrue($second->fresh()->isOrdersBlocked());

        $this->artisan('trading:panic', ['portfolio' => $first->id, '--resume' => true])->assertSuccessful();
        $this->assertFalse($first->fresh()->isOrdersBlocked());

        $this->artisan('trading:panic')->assertFailed();
    }

    // --- Avisos -----------------------------------------------------------

    public function test_owner_is_told_in_real_time_and_by_email_when_asked()
    {
        Event::fake([LiveOrderUpdated::class]);
        Notification::fake();
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio(['notify_live_email' => true]);

        app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto');

        Event::assertDispatched(LiveOrderUpdated::class, fn (LiveOrderUpdated $event) => $event->userId === $portfolio->user_id && $event->kind === 'sent');
        Notification::assertSentTo($portfolio->user, LiveOrderNotification::class);
    }

    public function test_failures_are_announced_and_no_email_goes_out_when_not_asked()
    {
        Event::fake([LiveOrderUpdated::class]);
        Notification::fake();
        $this->fakeBinance(order: fn () => Http::response(['code' => -1013, 'msg' => 'Filter failure: NOTIONAL'], 400));
        $portfolio = $this->autoPortfolio();

        app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto');

        Event::assertDispatched(LiveOrderUpdated::class, fn (LiveOrderUpdated $event) => $event->kind === 'failed' && str_contains($event->message, 'fora dos limites'));
        Notification::assertNothingSent();
    }

    public function test_a_guard_refusal_of_an_automatic_signal_is_announced()
    {
        Event::fake([LiveOrderUpdated::class]);
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio(['cash' => 0]);

        app(OrderExecutor::class)->execute($this->autoSignal($portfolio), 'auto');

        Event::assertDispatched(LiveOrderUpdated::class, fn (LiveOrderUpdated $event) => $event->kind === 'refused');
    }

    public function test_panic_is_announced_to_the_owner_only()
    {
        Event::fake([LiveOrderUpdated::class]);
        $this->fakeBinance();
        $portfolio = $this->autoPortfolio();

        app(PanicStop::class)->stop($portfolio, 'console');

        Event::assertDispatched(LiveOrderUpdated::class, fn (LiveOrderUpdated $event) => $event->kind === 'panic' && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$portfolio->user_id);
    }
}
