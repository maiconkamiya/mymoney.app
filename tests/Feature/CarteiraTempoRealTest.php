<?php

namespace Tests\Feature;

use App\Enums\PortfolioMode;
use App\Enums\SignalStage;
use App\Events\CotacaoAtualizada;
use App\Events\PortfolioChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Models\User;
use App\Trading\TradingHalt;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'portfolios/Show',
                'X-Inertia-Partial-Data' => 'positions',
            ])
            ->assertOk()
            ->assertJsonStructure(['props' => ['positions']])
            ->assertJsonMissingPath('props.charts')
            ->assertJsonMissingPath('props.performance');
    }

    public function test_caixa_alterado_por_increment_avisa_o_saldo()
    {
        $portfolio = Portfolio::factory()->create();
        Event::fake([PortfolioChanged::class]);

        $portfolio->decrement('cash', 5);

        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => $event->portfolioId === $portfolio->id
            && in_array(PortfolioChanged::SCOPE_BALANCE, $event->scopes, true));
    }

    public function test_cada_rodada_do_motor_avisa_as_micro_operacoes()
    {
        $portfolio = Portfolio::factory()->create(['micro_enabled' => true, 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2]);
        Portfolio::factory()->create(['micro_enabled' => false]);
        Event::fake([PortfolioChanged::class]);

        Artisan::call('micro:run');

        Event::assertDispatchedTimes(PortfolioChanged::class, 1);
        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => $event->portfolioId === $portfolio->id
            && $event->scopes === [PortfolioChanged::SCOPE_MICRO]);
    }

    public function test_ativos_acompanhados_avisam_as_outras_abas_e_sao_so_do_dono()
    {
        $portfolio = Portfolio::factory()->create();
        $asset = Asset::factory()->create(['market' => $portfolio->market, 'active' => true, 'is_benchmark' => false]);
        Event::fake([PortfolioChanged::class]);

        $this->actingAs(User::factory()->create())
            ->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => [$asset->id]])
            ->assertForbidden();
        Event::assertNotDispatched(PortfolioChanged::class);

        $this->actingAs($portfolio->user)
            ->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => [$asset->id]])
            ->assertRedirect();

        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => in_array(PortfolioChanged::SCOPE_ASSETS, $event->scopes, true));
    }

    public function test_parar_tudo_global_avisa_as_carteiras_reais()
    {
        $live = Portfolio::factory()->create(['mode' => PortfolioMode::Live, 'broker' => 'binance']);
        Portfolio::factory()->create();
        Event::fake([PortfolioChanged::class]);

        app(TradingHalt::class)->halt('teste');

        Event::assertDispatchedTimes(PortfolioChanged::class, 1);
        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => $event->portfolioId === $live->id);
    }

    public function test_posicao_da_carteira_simulada_traz_o_preco_da_ultima_vela()
    {
        $portfolio = Portfolio::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'qty' => 2, 'avg_price' => 100, 'opened_at' => now()]);
        Candle::insert([['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute(), 'open' => 110, 'high' => 110, 'low' => 110, 'close' => 110, 'volume' => 1]]);

        $this->actingAs($portfolio->user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page
                ->where('positions.0.last_price', 110)
                ->where('positions.0.value', 220)
                ->etc());
    }

    public function test_cotacao_sai_direto_sem_esperar_a_fila()
    {
        $this->assertTrue(is_subclass_of(CotacaoAtualizada::class, ShouldBroadcastNow::class));
    }
}
