<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Data\OrderRequest;
use App\Trading\Micro\MicroTrader;
use App\Trading\Risk\RiskGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

/**
 * #216: com a guarda de risco barrando as compras pela queda desde o maior saldo, o card de micro
 * mostra o bloqueio (não "Operando") e o motor grava o começo e o fim dele uma vez só.
 */
class QuedaMaximaMicroTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($this->asset, 100);
    }

    /**
     * A simulada #3: maior saldo 20 ontem, 17,98 desde o início de hoje (queda de 10,1%, sem perda no dia).
     */
    private function droppedPortfolio(array $attributes = []): Portfolio
    {
        $portfolio = Portfolio::factory()->create($attributes + [
            'initial_cash' => 20,
            'cash' => 17.98,
            'micro_enabled' => true,
            'micro_order_value' => 5,
            'micro_net_margin_pct' => 0.2,
        ]);
        $portfolio->snapshots()->create(['taken_at' => now()->subDay(), 'equity' => 20, 'cash' => 20, 'exposure' => 0]);
        $portfolio->snapshots()->create(['taken_at' => now()->startOfDay()->addMinute(), 'equity' => 17.98, 'cash' => 17.98, 'exposure' => 0]);

        return $portfolio;
    }

    public function test_the_state_shows_the_block_the_guard_applies()
    {
        $portfolio = $this->droppedPortfolio();
        $buy = new OrderRequest($this->asset, OrderSide::Buy, 0.01, stopPrice: 95);

        $this->assertSame(['Drawdown máximo atingido.'], app(RiskGuard::class)->check($portfolio, $buy, ownerSized: true)->reasons);

        $state = app(MicroTrader::class)->state($portfolio);
        $this->assertSame('drawdown_limit', $state['code']);
        $this->assertSame('Bloqueado pela queda de 10,1% desde o maior saldo (limite 10%)', $state['label']);

        $drawdown = app(MicroTrader::class)->drawdown($portfolio);
        $this->assertTrue($drawdown['blocked']);
        $this->assertEqualsWithDelta(18.0, $drawdown['release_equity'], 0.0001);
        $this->assertSame(0, app(MicroTrader::class)->run($portfolio));
    }

    public function test_the_engine_records_the_start_and_the_end_once()
    {
        $portfolio = $this->droppedPortfolio();
        $trader = app(MicroTrader::class);

        $trader->run($portfolio);
        $trader->run($portfolio);

        $this->assertSame(1, AuditLog::where('action', 'micro.drawdown_blocked')->count());
        $log = AuditLog::where('action', 'micro.drawdown_blocked')->sole();
        $this->assertSame($portfolio->id, $log->subject_id);
        $this->assertEqualsWithDelta(10.1, $log->payload['drop_pct'], 0.001);
        $this->assertEqualsWithDelta(18.0, $log->payload['release_equity'], 0.001);

        // O deploy limpa o cache: o último registro diz que o bloqueio já foi gravado.
        Cache::flush();
        $trader->run($portfolio);
        $this->assertSame(1, AuditLog::where('action', 'micro.drawdown_blocked')->count());

        // Novo aporte na simulada: entra no saldo e no maior saldo, e a queda fica abaixo do limite.
        $portfolio->deposits()->create(['amount' => 5, 'deposited_at' => now()]);
        $portfolio->update(['cash' => 22.98]);
        $trader->run($portfolio->fresh());

        $this->assertSame('active', $trader->state($portfolio->fresh())['code']);
        $this->assertSame(1, AuditLog::where('action', 'micro.drawdown_released')->count());

        $trader->run($portfolio->fresh());
        $this->assertSame(1, AuditLog::where('action', 'micro.drawdown_released')->count());
    }

    public function test_a_drop_below_the_limit_keeps_trading()
    {
        $portfolio = $this->droppedPortfolio(['cash' => 18.5]);

        $this->assertSame('active', app(MicroTrader::class)->state($portfolio)['code']);
        app(MicroTrader::class)->run($portfolio);
        $this->assertSame(0, AuditLog::where('action', 'micro.drawdown_blocked')->count());
    }

    public function test_the_card_shows_the_block_only_to_the_owner()
    {
        $portfolio = $this->droppedPortfolio();

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.state.code', 'drawdown_limit')
            ->where('micro.drawdown.blocked', true)
            ->where('micro.drawdown.pct', 10.1)
            ->where('micro.drawdown.limit_pct', 10)
            ->where('micro.drawdown.peak', 20)
            ->where('micro.drawdown.release_equity', 18)
            ->where('micro.drawdown.profile', 'Padrão'));

        $this->actingAs(User::factory()->create())->get(route('portfolios.show', $portfolio))->assertForbidden();
    }
}
