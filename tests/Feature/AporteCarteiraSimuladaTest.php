<?php

namespace Tests\Feature;

use App\Enums\PortfolioMode;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\PortfolioDeposit;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Reports\PerformanceReport;
use App\Trading\PortfolioValuation;
use App\Trading\Risk\RiskGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AporteCarteiraSimuladaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dono_aporta_e_o_valor_entra_no_caixa_e_no_total_investido()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['initial_cash' => 20, 'cash' => 12.5]);

        $this->actingAs($user)
            ->post(route('portfolios.deposits.store', $portfolio), ['amount' => 10])
            ->assertRedirect();

        $portfolio->refresh();
        $this->assertEquals(30, $portfolio->initial_cash);
        $this->assertEquals(22.5, $portfolio->cash);
        $deposit = PortfolioDeposit::sole();
        $this->assertEquals(10, $deposit->amount);
        $this->assertSame($portfolio->id, $deposit->portfolio_id);
        $log = AuditLog::where('action', 'portfolio.deposit')->sole();
        $this->assertEquals(10, $log->payload['amount']);
        $this->assertSame('user:'.$user->id, $log->actor);
    }

    public function test_outra_conta_recebe_403_e_nada_muda()
    {
        $portfolio = Portfolio::factory()->create(['initial_cash' => 20, 'cash' => 20]);

        $this->actingAs(User::factory()->create())
            ->post(route('portfolios.deposits.store', $portfolio), ['amount' => 10])
            ->assertForbidden();

        $this->assertEquals(20, $portfolio->fresh()->cash);
        $this->assertSame(0, PortfolioDeposit::count());
    }

    public function test_carteira_real_nao_aceita_aporte_pelo_app()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['mode' => PortfolioMode::Live, 'broker' => 'binance', 'initial_cash' => 40, 'cash' => 40]);

        $this->actingAs($user)
            ->post(route('portfolios.deposits.store', $portfolio), ['amount' => 10])
            ->assertForbidden();

        $this->assertEquals(40, $portfolio->fresh()->cash);
        $this->assertSame(0, PortfolioDeposit::count());
    }

    public function test_valor_invalido_e_recusado()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();

        $this->actingAs($user)->post(route('portfolios.deposits.store', $portfolio), ['amount' => 0])->assertSessionHasErrors('amount');
        $this->actingAs($user)->post(route('portfolios.deposits.store', $portfolio), ['amount' => -5])->assertSessionHasErrors('amount');
        $this->actingAs($user)->post(route('portfolios.deposits.store', $portfolio), [])->assertSessionHasErrors('amount');
        $this->assertSame(0, PortfolioDeposit::count());
    }

    public function test_aporte_nao_conta_como_ganho_hoje_nem_como_perda_amanha()
    {
        $this->travelTo(now()->setTime(12, 0));
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['initial_cash' => 20, 'cash' => 19]);
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDay()->setTime(23, 57), 'equity' => 19, 'cash' => 19, 'exposure' => 0, 'drawdown' => 0.05]);

        $this->actingAs($user)->post(route('portfolios.deposits.store', $portfolio), ['amount' => 10]);
        $portfolio->refresh();

        $valuation = app(PortfolioValuation::class);
        $guard = app(RiskGuard::class);
        $this->assertEquals(29, $valuation->startOfDayEquity($portfolio));
        $this->assertEqualsWithDelta(0, $guard->dailyLoss($portfolio), 0.0001);

        // Snapshot do fim do dia já tem o aporte; no dia seguinte a referência é ele.
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->setTime(23, 57), 'equity' => 29, 'cash' => 29, 'exposure' => 0, 'drawdown' => 0.05]);
        $this->travelTo(now()->addDay()->setTime(9, 0));

        $this->assertEquals(29, $valuation->startOfDayEquity($portfolio));
        $this->assertEqualsWithDelta(0, $guard->dailyLoss($portfolio), 0.0001);
    }

    public function test_pico_soma_os_aportes_feitos_depois_de_cada_ponto()
    {
        $this->travelTo(now()->setTime(12, 0));
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['initial_cash' => 20, 'cash' => 22]);
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDays(2), 'equity' => 22, 'cash' => 22, 'exposure' => 0, 'drawdown' => 0]);

        $this->actingAs($user)->post(route('portfolios.deposits.store', $portfolio), ['amount' => 10]);
        $this->travel(1)->hours();
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now(), 'equity' => 31, 'cash' => 31, 'exposure' => 0, 'drawdown' => 0]);
        $portfolio->refresh();

        // 22 antes do aporte vale 32 hoje; o saldo inicial (30) e o 31 depois do aporte ficam abaixo.
        $this->assertEquals(32, app(PortfolioValuation::class)->peakEquity($portfolio));
    }

    public function test_relatorio_nao_le_o_aporte_como_ganho()
    {
        $this->travelTo(now()->setTime(12, 0));
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['initial_cash' => 20, 'cash' => 20, 'created_at' => now()->subDays(3)]);
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDays(2), 'equity' => 18, 'cash' => 18, 'exposure' => 0, 'drawdown' => 0.1]);
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now()->subDay(), 'equity' => 20, 'cash' => 20, 'exposure' => 0, 'drawdown' => 0]);

        $this->actingAs($user)->post(route('portfolios.deposits.store', $portfolio), ['amount' => 20]);
        $this->travel(1)->hours();
        PortfolioSnapshot::create(['portfolio_id' => $portfolio->id, 'taken_at' => now(), 'equity' => 40, 'cash' => 40, 'exposure' => 0, 'drawdown' => 0]);

        $report = app(PerformanceReport::class)->build($portfolio->refresh());

        $this->assertSame([40.0, 38.0, 40.0, 40.0], array_column($report['curve'], 'equity'));
        $this->assertEqualsWithDelta(0, $report['return'], 0.0001);
        $this->assertEqualsWithDelta(0.05, $report['max_drawdown'], 0.0001);
    }

    public function test_tela_mostra_os_aportes_do_dono()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['initial_cash' => 20, 'cash' => 20]);
        $this->actingAs($user)->post(route('portfolios.deposits.store', $portfolio), ['amount' => 7.5]);

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page
                ->where('portfolio.deposits.0.amount', 7.5)
                ->where('portfolio.initial_cash', 27.5)
                ->etc());
    }
}
