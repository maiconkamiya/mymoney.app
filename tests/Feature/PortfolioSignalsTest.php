<?php

namespace Tests\Feature;

use App\Enums\SignalStage;
use App\Http\Controllers\PortfolioSignalsController;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortfolioSignalsTest extends TestCase
{
    use RefreshDatabase;

    private function signal(Portfolio $portfolio, Asset $asset, SignalStage $stage = SignalStage::Rejected): Signal
    {
        return Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $asset->id,
            'stage' => $stage,
            'side' => 'buy',
            'score' => 0.5,
        ]);
    }

    public function test_a_carteira_mostra_so_o_ultimo_sinal_de_cada_ativo()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT']);
        $this->signal($portfolio, $btc);
        $this->signal($portfolio, $eth);
        $latestBtc = $this->signal($portfolio, $btc, SignalStage::Approved);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolios/Show')
                ->has('signals', 2)
                ->where('signals.0.id', $latestBtc->id)
                ->where('signals_total', 3));
    }

    public function test_o_historico_vem_do_mais_novo_ao_mais_antigo_em_paginas()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $asset = Asset::factory()->create();
        $total = PortfolioSignalsController::PER_PAGE + 5;
        $ids = collect(range(1, $total))->map(fn () => $this->signal($portfolio, $asset)->id);

        $this->actingAs($user)->get(route('portfolios.signals', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolios/Sinais')
                ->has('signals.data', PortfolioSignalsController::PER_PAGE)
                ->where('signals.data.0.id', $ids->last()));

        $this->actingAs($user)->get(route('portfolios.signals', ['portfolio' => $portfolio, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('signals.data', 5)
                ->where('signals.data.0.id', $ids[4]));
    }

    public function test_o_historico_filtra_por_ativo_e_resultado()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT']);
        $this->signal($portfolio, $btc);
        $this->signal($portfolio, $eth);
        $approved = $this->signal($portfolio, $eth, SignalStage::Approved);

        $this->actingAs($user)->get(route('portfolios.signals', ['portfolio' => $portfolio, 'ativo' => 'ETHUSDT']))
            ->assertInertia(fn (Assert $page) => $page->has('signals.data', 2)->where('symbols', ['BTCUSDT', 'ETHUSDT']));

        $this->actingAs($user)->get(route('portfolios.signals', ['portfolio' => $portfolio, 'resultado' => 'approved']))
            ->assertInertia(fn (Assert $page) => $page->has('signals.data', 1)->where('signals.data.0.id', $approved->id));

        $this->actingAs($user)->get(route('portfolios.signals', ['portfolio' => $portfolio, 'resultado' => 'inexistente']))
            ->assertSessionHasErrors('resultado');
    }

    public function test_outra_conta_recebe_403_no_historico()
    {
        $portfolio = Portfolio::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('portfolios.signals', $portfolio))->assertForbidden();
    }

    public function test_visitante_vai_para_o_login()
    {
        $this->get(route('portfolios.signals', Portfolio::factory()->create()))->assertRedirect(route('login'));
    }
}
