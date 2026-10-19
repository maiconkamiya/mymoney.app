<?php

namespace Tests\Feature;

use App\Enums\PortfolioMode;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\User;
use App\Trading\PortfolioValuation;
use App\Trading\Risk\RiskGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaldoInicialTest extends TestCase
{
    use RefreshDatabase;

    public function test_carteira_sem_operacao_so_troca_o_saldo_e_o_caixa()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 25000])
            ->assertRedirect();

        $portfolio->refresh();
        $this->assertEquals(25000, $portfolio->initial_cash);
        $this->assertEquals(25000, $portfolio->cash);
        $log = AuditLog::where('action', 'portfolio.initial_cash')->sole();
        $this->assertEquals(10000, $log->payload['old']);
        $this->assertEquals(25000, $log->payload['new']);
    }

    public function test_carteira_com_historico_ajusta_o_caixa_pela_diferenca_sem_apagar_nada()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['cash' => 7000]);
        $asset = Asset::factory()->create();
        $position = Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'qty' => 3, 'avg_price' => 1000, 'opened_at' => now()]);
        $snapshot = PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subHour(), 'equity' => 10100, 'cash' => 7000, 'exposure' => 3100, 'drawdown' => 0]);
        $before = app(PortfolioValuation::class)->equity($portfolio);

        $this->actingAs($user)
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 8000])
            ->assertRedirect();

        $portfolio->refresh();
        $this->assertEquals(8000, $portfolio->initial_cash);
        $this->assertEquals(5000, $portfolio->cash);
        $this->assertEqualsWithDelta($before - 2000, app(PortfolioValuation::class)->equity($portfolio), 0.01);
        $this->assertEquals(8100, $snapshot->fresh()->equity);
        $this->assertEquals(5000, $snapshot->fresh()->cash);
        $this->assertModelExists($position);
        $this->assertSame(-2000.0, (float) AuditLog::where('action', 'portfolio.initial_cash')->sole()->payload['cash_delta']);
    }

    public function test_reducao_que_deixaria_o_caixa_negativo_e_recusada()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['cash' => 500]);

        $this->actingAs($user)
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 5000])
            ->assertSessionHasErrors('initial_cash');

        $this->assertEquals(10000, $portfolio->fresh()->initial_cash);
    }

    public function test_valor_invalido_e_recusado()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();

        $this->actingAs($user)->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 0])->assertSessionHasErrors('initial_cash');
        $this->actingAs($user)->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => -50])->assertSessionHasErrors('initial_cash');
    }

    public function test_outro_usuario_recebe_403()
    {
        $portfolio = Portfolio::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 5000])
            ->assertForbidden();

        $this->assertEquals(10000, $portfolio->fresh()->initial_cash);
    }

    public function test_carteira_real_muda_so_o_valor_investido()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['mode' => PortfolioMode::Live, 'broker' => 'binance', 'initial_cash' => 1.0199052, 'cash' => 18.5]);
        $snapshot = PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDay(), 'equity' => 19.7, 'cash' => 18.5, 'exposure' => 1.2, 'drawdown' => 0]);

        $this->actingAs($user)
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 20])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $portfolio->refresh();
        $this->assertEquals(20, $portfolio->initial_cash);
        $this->assertEquals(18.5, $portfolio->cash);
        $this->assertEquals(19.7, $snapshot->fresh()->equity);
        $log = AuditLog::where('action', 'portfolio.initial_cash')->sole();
        $this->assertSame('user:'.$user->id, $log->actor);
        $this->assertEqualsWithDelta(1.0199052, $log->payload['old'], 1e-9);
        $this->assertEquals(20, $log->payload['new']);
        $this->assertEquals(0, $log->payload['cash_delta']);
    }

    public function test_valor_investido_da_carteira_real_nao_infla_o_pico_nem_a_perda_do_dia()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['mode' => PortfolioMode::Live, 'broker' => 'binance', 'initial_cash' => 30, 'cash' => 20]);
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDay()->setTime(23, 55), 'equity' => 21, 'cash' => 20, 'exposure' => 1, 'drawdown' => 0]);
        $valuation = app(PortfolioValuation::class);

        $this->assertEquals(21, $valuation->peakEquity($portfolio));
        $this->assertEquals(21, $valuation->startOfDayEquity($portfolio));
    }

    public function test_carteira_real_sem_snapshot_usa_o_patrimonio_observado_e_nao_o_valor_investido()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['mode' => PortfolioMode::Live, 'broker' => 'binance', 'initial_cash' => 20, 'cash' => 19.72]);
        $this->actingAs($user)->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 40])->assertSessionHasNoErrors();
        $portfolio->refresh();
        $valuation = app(PortfolioValuation::class);
        $guard = app(RiskGuard::class);

        $this->assertEquals(19.72, $valuation->peakEquity($portfolio));
        $this->assertEquals(19.72, $valuation->startOfDayEquity($portfolio));
        $this->assertFalse($guard->dailyLossReached($portfolio));
        $this->assertSame(1, $portfolio->snapshots()->count());

        $this->actingAs($user)->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 80])->assertSessionHasNoErrors();
        $portfolio->refresh();

        $this->assertEquals(19.72, $valuation->peakEquity($portfolio));
        $this->assertEquals(19.72, $valuation->startOfDayEquity($portfolio));
        $this->assertSame(1, $portfolio->snapshots()->count());
    }

    public function test_patrimonio_observado_da_carteira_real_vale_ate_o_fim_do_dia()
    {
        $portfolio = Portfolio::factory()->create(['mode' => PortfolioMode::Live, 'broker' => 'binance', 'initial_cash' => 40, 'cash' => 20]);
        $valuation = app(PortfolioValuation::class);

        $this->assertEquals(20, $valuation->startOfDayEquity($portfolio));

        $portfolio->update(['cash' => 19]);

        $this->assertEquals(20, $valuation->startOfDayEquity($portfolio));
        $this->assertTrue(app(RiskGuard::class)->dailyLossReached($portfolio));
    }

    public function test_carteira_real_sem_saldo_nao_grava_referencia()
    {
        $portfolio = Portfolio::factory()->create(['mode' => PortfolioMode::Live, 'broker' => 'binance', 'initial_cash' => 40, 'cash' => 0]);

        $this->assertEquals(0, app(PortfolioValuation::class)->startOfDayEquity($portfolio));
        $this->assertSame(0, $portfolio->snapshots()->count());
    }

    public function test_carteira_simulada_sem_snapshot_continua_no_saldo_inicial()
    {
        $portfolio = Portfolio::factory()->create(['initial_cash' => 10000, 'cash' => 9000]);
        $valuation = app(PortfolioValuation::class);

        $this->assertEquals(10000, $valuation->peakEquity($portfolio));
        $this->assertEquals(10000, $valuation->startOfDayEquity($portfolio));
        $this->assertSame(0, $portfolio->snapshots()->count());
    }

    public function test_carteira_real_recusa_valor_investido_zero()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['mode' => PortfolioMode::Live, 'broker' => 'binance']);

        $this->actingAs($user)
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 0])
            ->assertSessionHasErrors('initial_cash');

        $this->assertEquals(10000, $portfolio->fresh()->initial_cash);
    }

    public function test_outro_usuario_recebe_403_na_carteira_real()
    {
        $portfolio = Portfolio::factory()->create(['mode' => PortfolioMode::Live, 'broker' => 'binance']);

        $this->actingAs(User::factory()->create())
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 20])
            ->assertForbidden();

        $this->assertEquals(10000, $portfolio->fresh()->initial_cash);
        $this->assertSame(0, AuditLog::count());
    }
}
