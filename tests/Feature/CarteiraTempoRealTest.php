<?php

namespace Tests\Feature;

use App\Enums\SignalStage;
use App\Events\PortfolioChanged;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CarteiraTempoRealTest extends TestCase
{
    use RefreshDatabase;

    public function test_mudar_o_saldo_da_carteira_avisa_o_canal_do_dono()
    {
        $portfolio = Portfolio::factory()->create();
        Event::fake([PortfolioChanged::class]);

        $portfolio->update(['cash' => $portfolio->cash - 10]);

        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => $event->portfolioId === $portfolio->id
            && in_array(PortfolioChanged::SCOPE_BALANCE, $event->scopes, true)
            && $event->broadcastAs() === 'portfolio.changed'
            && $event->broadcastOn()[0]->name === 'private-portfolios.'.$portfolio->id);
    }

    public function test_um_sinal_novo_avisa_com_o_escopo_de_sinais()
    {
        $portfolio = Portfolio::factory()->create();
        Event::fake([PortfolioChanged::class]);

        Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => Asset::factory()->create()->id,
            'stage' => SignalStage::Approved,
            'side' => 'buy',
        ]);

        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => $event->scopes === [PortfolioChanged::SCOPE_SIGNALS]);
    }

    public function test_recarga_parcial_devolve_so_os_blocos_pedidos()
    {
        $portfolio = Portfolio::factory()->create();

        $this->actingAs($portfolio->user)
            ->get(route('portfolios.show', $portfolio), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'portfolios/Show',
                'X-Inertia-Partial-Data' => 'positions',
            ])
            ->assertOk()
            ->assertJsonStructure(['props' => ['positions']])
            ->assertJsonMissingPath('props.charts')
            ->assertJsonMissingPath('props.performance');
    }
}
