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

    public function test_carteira_real_nao_tem_saldo_inicial_editavel()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['mode' => PortfolioMode::Live, 'broker' => 'binance']);

        $this->actingAs($user)
            ->patch(route('portfolios.initial-cash', $portfolio), ['initial_cash' => 5000])
            ->assertSessionHasErrors('initial_cash');

        $this->assertEquals(10000, $portfolio->fresh()->initial_cash);
    }
}
