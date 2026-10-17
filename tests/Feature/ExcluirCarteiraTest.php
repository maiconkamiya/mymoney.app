<?php

namespace Tests\Feature;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PortfolioMode;
use App\Enums\SignalStage;
use App\Models\AgentVote;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Fill;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExcluirCarteiraTest extends TestCase
{
    use RefreshDatabase;

    public function test_dono_exclui_a_carteira_simulada_com_tudo_que_esta_ligado_a_ela()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Teste X']);
        $asset = Asset::factory()->create();
        $signal = Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Executed]);
        AgentVote::create(['signal_id' => $signal->id, 'agent' => 'technical', 'round' => 1, 'action' => 'buy', 'confidence' => 0.7]);
        $order = Order::create(['portfolio_id' => $portfolio->id, 'signal_id' => $signal->id, 'asset_id' => $asset->id, 'broker' => 'paper', 'side' => OrderSide::Buy, 'type' => OrderType::Market, 'qty' => 1, 'status' => OrderStatus::Filled]);
        Fill::create(['order_id' => $order->id, 'qty' => 1, 'price' => 100, 'fee' => 0, 'executed_at' => now()]);
        Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'signal_id' => $signal->id, 'qty' => 1, 'avg_price' => 100, 'opened_at' => now()]);

        $this->actingAs($user)
            ->delete(route('portfolios.destroy', $portfolio), ['confirmation' => 'Teste X'])
            ->assertRedirect(route('portfolios.index'));

        $this->assertModelMissing($portfolio);
        $this->assertSame(0, Signal::count() + AgentVote::count() + Order::count() + Fill::count() + Position::count());
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.deleted', 'actor' => 'user:'.$user->id]);
        $this->assertSame('Teste X', AuditLog::latest('id')->first()->payload['name']);
    }

    public function test_nome_errado_nao_exclui()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Teste X']);

        $this->actingAs($user)
            ->delete(route('portfolios.destroy', $portfolio), ['confirmation' => 'outro'])
            ->assertSessionHasErrors('confirmation');

        $this->assertModelExists($portfolio);
    }

    public function test_outro_usuario_recebe_403_e_a_carteira_fica()
    {
        $portfolio = Portfolio::factory()->create(['name' => 'Alheia']);

        $this->actingAs(User::factory()->create())
            ->delete(route('portfolios.destroy', $portfolio), ['confirmation' => 'Alheia'])
            ->assertForbidden();

        $this->assertModelExists($portfolio);
    }

    public function test_carteira_real_nao_pode_ser_excluida()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Conta Binance', 'mode' => PortfolioMode::Live, 'broker' => 'binance']);

        $this->actingAs($user)
            ->delete(route('portfolios.destroy', $portfolio), ['confirmation' => 'Conta Binance'])
            ->assertSessionHasErrors('portfolio');

        $this->assertModelExists($portfolio);
    }

    public function test_sinal_em_andamento_bloqueia_a_exclusao()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Teste X']);
        Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => Asset::factory()->create()->id, 'stage' => SignalStage::Approved]);

        $this->actingAs($user)
            ->delete(route('portfolios.destroy', $portfolio), ['confirmation' => 'Teste X'])
            ->assertSessionHasErrors(['portfolio' => 'A carteira tem 1 sinal em andamento. Confirme ou descarte os sinais e aguarde as ordens antes de excluir.']);

        $this->assertModelExists($portfolio);
    }

    public function test_ordem_pendente_bloqueia_a_exclusao()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Teste X']);
        Order::create(['portfolio_id' => $portfolio->id, 'asset_id' => Asset::factory()->create()->id, 'broker' => 'paper', 'side' => OrderSide::Buy, 'type' => OrderType::Limit, 'qty' => 1, 'status' => OrderStatus::Pending]);

        $this->actingAs($user)
            ->delete(route('portfolios.destroy', $portfolio), ['confirmation' => 'Teste X'])
            ->assertSessionHasErrors('portfolio');

        $this->assertModelExists($portfolio);
    }

    public function test_sinal_ja_decidido_nao_bloqueia()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Teste X']);
        Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => Asset::factory()->create()->id, 'stage' => SignalStage::Rejected]);

        $this->actingAs($user)
            ->delete(route('portfolios.destroy', $portfolio), ['confirmation' => 'Teste X'])
            ->assertRedirect(route('portfolios.index'));

        $this->assertModelMissing($portfolio);
    }
}
