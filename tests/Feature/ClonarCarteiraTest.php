<?php

namespace Tests\Feature;

use App\Enums\MicroRule;
use App\Enums\PortfolioMode;
use App\Enums\SignalStage;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\BrokerCredential;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class ClonarCarteiraTest extends TestCase
{
    use BuildsMarket;
    use RefreshDatabase;

    public function test_dono_clona_a_carteira_real_como_simulada_com_ativos_e_card_micro()
    {
        $user = User::factory()->create();
        $credential = BrokerCredential::create([
            'user_id' => $user->id, 'broker' => 'binance', 'label' => 'teste',
            'api_key' => 'key', 'api_secret' => 'secret', 'can_withdraw' => false, 'verified_at' => now(),
        ]);
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $eth = Asset::factory()->create(['symbol' => 'ETHUSDT']);
        $this->quote($btc, 100);

        $live = Portfolio::factory()->for($user)->create([
            'name' => 'Conta Binance',
            'mode' => PortfolioMode::Live,
            'broker' => 'binance',
            'broker_credential_id' => $credential->id,
            'initial_cash' => 25,
            'cash' => 12.5,
            'auto_execute' => true,
            'live_auto_confirmed_at' => now(),
            'micro_enabled' => false,
            'micro_order_value' => 5,
            'micro_net_margin_pct' => 0.7,
            'micro_daily_target' => 0.5,
            'micro_daily_loss_limit' => 1,
            'micro_daily_operations' => 20,
            'micro_wallet_pct' => 80,
            'micro_rule' => MicroRule::TrendPullback,
            'micro_liquidate_asset_ids' => [$btc->id],
            'sync_report' => ['holdings' => []],
        ]);
        $live->assets()->sync([$btc->id, $eth->id]);
        $signal = Signal::create(['portfolio_id' => $live->id, 'asset_id' => $btc->id, 'stage' => SignalStage::Executed]);
        Position::create(['portfolio_id' => $live->id, 'asset_id' => $btc->id, 'signal_id' => $signal->id, 'qty' => 0.08, 'avg_price' => 90, 'opened_at' => now()]);

        $response = $this->actingAs($user)->post(route('portfolios.clone', $live));

        $clone = Portfolio::query()->whereKeyNot($live->id)->sole();
        $response->assertRedirect(route('portfolios.show', $clone));

        // Patrimônio da origem: 12,50 em caixa + 0,08 BTC a 100.
        $this->assertSame('Conta Binance (simulada)', $clone->name);
        $this->assertSame($user->id, $clone->user_id);
        $this->assertSame(PortfolioMode::Paper, $clone->mode);
        $this->assertSame('paper', $clone->broker);
        $this->assertNull($clone->broker_credential_id);
        $this->assertEqualsWithDelta(20.5, $clone->initial_cash, 0.001);
        $this->assertEqualsWithDelta(20.5, $clone->cash, 0.001);
        $this->assertFalse($clone->auto_execute);
        $this->assertNull($clone->live_auto_confirmed_at);
        $this->assertNull($clone->sync_report);
        $this->assertTrue($clone->micro_enabled);
        $this->assertEqualsWithDelta(5, $clone->micro_order_value, 0.001);
        $this->assertEqualsWithDelta(0.7, $clone->micro_net_margin_pct, 0.001);
        $this->assertEqualsWithDelta(0.5, $clone->micro_daily_target, 0.001);
        $this->assertEqualsWithDelta(1, $clone->micro_daily_loss_limit, 0.001);
        $this->assertSame(20, $clone->micro_daily_operations);
        $this->assertSame(80, $clone->micro_wallet_pct);
        $this->assertSame(MicroRule::TrendPullback, $clone->micro_rule);
        $this->assertNull($clone->micro_liquidate_asset_ids);
        $this->assertEqualsCanonicalizing([$btc->id, $eth->id], $clone->assets()->pluck('assets.id')->all());

        $this->assertSame(0, $clone->positions()->count() + $clone->signals()->count() + $clone->orders()->count() + $clone->snapshots()->count());
        $this->assertSame(1, $live->positions()->count());
        $this->assertTrue($live->fresh()->autoExecutesLive());
        $this->assertDatabaseHas('audit_log', ['action' => 'portfolio.cloned', 'actor' => 'user:'.$user->id]);
        $this->assertSame($live->id, AuditLog::latest('id')->first()->payload['from_portfolio_id']);
    }

    public function test_clone_de_simulada_ganha_sufixo_copia_e_carteira_sem_ativos_escolhidos_continua_acompanhando_todos()
    {
        $user = User::factory()->create();
        $paper = Portfolio::factory()->for($user)->create(['name' => 'Teste', 'cash' => 1000, 'ends_at' => now()->subDay()]);

        $this->actingAs($user)->post(route('portfolios.clone', $paper))->assertRedirect();

        $clone = Portfolio::query()->whereKeyNot($paper->id)->sole();
        $this->assertSame('Teste (cópia)', $clone->name);
        $this->assertSame(0, $clone->assets()->count());
        $this->assertTrue($clone->ends_at->isAfter(now()->addDays(29)));
    }

    public function test_outro_usuario_recebe_403_e_nada_e_criado()
    {
        $portfolio = Portfolio::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('portfolios.clone', $portfolio))
            ->assertForbidden();

        $this->assertSame(1, Portfolio::count());
    }

    public function test_carteira_sem_patrimonio_nao_e_clonada()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['cash' => 0]);

        $this->actingAs($user)
            ->post(route('portfolios.clone', $portfolio))
            ->assertSessionHasErrors('clone');

        $this->assertSame(1, Portfolio::count());
    }

    public function test_visitante_vai_para_o_login()
    {
        $portfolio = Portfolio::factory()->create();

        $this->post(route('portfolios.clone', $portfolio))->assertRedirect(route('login'));
    }
}
