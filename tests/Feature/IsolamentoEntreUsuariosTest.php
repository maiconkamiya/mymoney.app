<?php

namespace Tests\Feature;

use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class IsolamentoEntreUsuariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_outro_usuario_recebe_403_em_tudo_que_toca_a_carteira()
    {
        $portfolio = Portfolio::factory()->create();
        $signal = Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => Asset::factory()->create()->id,
            'stage' => SignalStage::Approved,
            'side' => 'buy',
        ]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('portfolios.show', $portfolio))->assertForbidden();
        $this->actingAs($intruder)->get(route('portfolios.agents', $portfolio))->assertForbidden();
        $this->actingAs($intruder)->get(route('portfolios.signals', $portfolio))->assertForbidden();
        $this->actingAs($intruder)->get(route('portfolios.reports.tax', $portfolio))->assertForbidden();
        $this->actingAs($intruder)->put(route('portfolios.micro', $portfolio), ['micro_enabled' => '1', 'micro_order_value' => 20, 'micro_net_margin_pct' => 0.2])->assertForbidden();
        $this->actingAs($intruder)->post(route('signals.confirm', $signal))->assertForbidden();
        $this->actingAs($intruder)->post(route('signals.reject', $signal))->assertForbidden();
    }

    public function test_a_lista_de_carteiras_mostra_so_as_do_proprio_usuario()
    {
        Portfolio::factory()->create(['name' => 'Alheia']);
        $user = User::factory()->create();
        Portfolio::factory()->create(['user_id' => $user->id, 'name' => 'Minha']);

        $this->actingAs($user)->get(route('portfolios.index'))
            ->assertSee('Minha')
            ->assertDontSee('Alheia');
    }

    public function test_visitante_sem_login_e_redirecionado()
    {
        $portfolio = Portfolio::factory()->create();

        $this->get(route('portfolios.show', $portfolio))->assertRedirect(route('login'));
        $this->get(route('binance.edit'))->assertRedirect(route('login'));
    }

    public function test_canal_privado_da_carteira_so_autoriza_o_dono()
    {
        $portfolio = Portfolio::factory()->create();
        $canal = 'private-portfolios.'.$portfolio->id.'.agents';

        // O ambiente de teste transmite para "null", que autoriza tudo: usa o driver real.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'chave',
            'broadcasting.connections.reverb.secret' => 'segredo',
            'broadcasting.connections.reverb.app_id' => 'app',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');

        $this->actingAs($portfolio->user)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => $canal])->assertOk();
        $this->actingAs(User::factory()->create())->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => $canal])->assertForbidden();
    }
}
