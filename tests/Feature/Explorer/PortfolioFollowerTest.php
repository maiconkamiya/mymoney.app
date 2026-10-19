<?php

namespace Tests\Feature\Explorer;

use App\Explorer\PortfolioFollower;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\MicroCycle;
use App\Models\OpportunityScan;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortfolioFollowerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    private function asset(string $symbol): Asset
    {
        return Asset::query()->where('symbol', $symbol)->first()
            ?? Asset::factory()->create(['symbol' => $symbol, 'name' => substr($symbol, 0, -4)]);
    }

    /**
     * Uma varredura com os pares na ordem do ranking.
     *
     * @param  list<string>  $symbols
     */
    private function scan(array $symbols, float $firstScore = 90): OpportunityScan
    {
        $scan = OpportunityScan::create(['pairs_seen' => 100, 'pairs_passed' => count($symbols)]);

        foreach ($symbols as $index => $symbol) {
            $this->asset($symbol);
            $scan->opportunities()->create([
                'symbol' => $symbol,
                'base' => substr($symbol, 0, -4),
                'rank' => $index + 1,
                'score' => $firstScore - $index,
                'breakout_score' => $firstScore - $index,
                'price' => 1,
                'change_24h_pct' => 3,
                'quote_volume' => 5_000_000,
                'spread_pct' => 0.05,
                'reasons' => ['Teste.'],
            ]);
        }

        return $scan;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function following(array $attributes = []): Portfolio
    {
        return Portfolio::factory()->create($attributes + ['explorer_follow' => true, 'explorer_follow_size' => 3, 'explorer_follow_min_hours' => 0]);
    }

    /**
     * @return list<string>
     */
    private function tracked(Portfolio $portfolio): array
    {
        return $portfolio->assets()->orderBy('symbol')->pluck('symbol')->all();
    }

    public function test_lista_vira_o_top_do_explorador_e_sai_quem_caiu(): void
    {
        $portfolio = $this->following();
        $portfolio->assets()->sync([$this->asset('OLDUSDT')->id, $this->asset('AAAUSDT')->id]);

        $result = app(PortfolioFollower::class)->syncPortfolio($portfolio, $this->scan(['AAAUSDT', 'BBBUSDT', 'CCCUSDT', 'DDDUSDT']));

        $this->assertSame(['AAAUSDT', 'BBBUSDT', 'CCCUSDT'], $this->tracked($portfolio));
        $this->assertSame(['BBBUSDT', 'CCCUSDT'], $result['added']);
        $this->assertSame(['OLDUSDT'], $result['removed']);

        $log = AuditLog::query()->where('action', PortfolioFollower::AUDIT_ACTION)->sole();
        $this->assertSame('Saiu do top 3 do explorador.', $log->payload['removed'][0]['reason']);
        $this->assertSame('2º do explorador, rompimento 89.', $log->payload['added'][0]['reason']);
        $this->assertSame('OLDUSDT', PortfolioFollower::history($portfolio)[2]['symbol']);
    }

    public function test_posicao_e_ciclo_micro_seguram_a_retirada_e_ocupam_vaga_sem_vender(): void
    {
        $portfolio = $this->following();
        $held = $this->asset('HELDUSDT');
        $cycle = $this->asset('CYCLEUSDT');
        $portfolio->assets()->sync([$held->id, $cycle->id]);

        Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $held->id, 'qty' => 10, 'avg_price' => 1, 'opened_at' => now()]);
        MicroCycle::create(['portfolio_id' => $portfolio->id, 'asset_id' => $cycle->id, 'status' => 'open', 'qty' => 1, 'entry_price' => 1, 'target_price' => 1.01, 'stop_price' => 0.99, 'gross_margin_pct' => 0.5, 'opened_at' => now()]);

        $result = app(PortfolioFollower::class)->syncPortfolio($portfolio, $this->scan(['AAAUSDT', 'BBBUSDT', 'CCCUSDT']));

        $this->assertSame(['AAAUSDT', 'CYCLEUSDT', 'HELDUSDT'], $this->tracked($portfolio));
        $this->assertSame(['HELDUSDT', 'CYCLEUSDT'], $result['held']);
        $this->assertSame(0, $portfolio->orders()->count());
        $this->assertNull(Position::query()->where('asset_id', $held->id)->value('closed_at'));
    }

    public function test_permanencia_minima_segura_quem_acabou_de_entrar(): void
    {
        $portfolio = $this->following(['explorer_follow_min_hours' => 2]);
        $follower = app(PortfolioFollower::class);

        $follower->syncPortfolio($portfolio, $this->scan(['AAAUSDT', 'BBBUSDT', 'CCCUSDT']));
        $follower->syncPortfolio($portfolio->refresh(), $this->scan(['DDDUSDT', 'EEEUSDT', 'FFFUSDT']));

        $this->assertSame(['AAAUSDT', 'BBBUSDT', 'CCCUSDT'], $this->tracked($portfolio));

        $this->travel(3)->hours();
        $follower->syncPortfolio($portfolio->refresh(), $this->scan(['DDDUSDT', 'EEEUSDT', 'FFFUSDT']));

        $this->assertSame(['DDDUSDT', 'EEEUSDT', 'FFFUSDT'], $this->tracked($portfolio));
    }

    public function test_varredura_vazia_com_erro_ou_carteira_desligada_nao_mexe(): void
    {
        $portfolio = $this->following();
        $portfolio->assets()->sync([$this->asset('OLDUSDT')->id]);
        $follower = app(PortfolioFollower::class);

        $follower->syncPortfolio($portfolio, $this->scan([]));
        $follower->syncPortfolio($portfolio, OpportunityScan::create(['error' => 'Binance indisponível']));

        $off = Portfolio::factory()->create();
        $follower->sync($this->scan(['AAAUSDT']));

        $this->assertSame(['AAAUSDT'], $this->tracked($portfolio->refresh()));
        $this->assertSame([], $this->tracked($off));
    }

    public function test_nao_inclui_par_com_fim_de_negociacao_nem_abaixo_da_nota_minima(): void
    {
        config(['explorer.follow.min_score' => 89]);
        $portfolio = $this->following();
        $scan = $this->scan(['AAAUSDT', 'ENDUSDT', 'CCCUSDT']);
        $this->asset('ENDUSDT')->update(['trading_ends_on' => now()->addDays(3)->toDateString()]);

        app(PortfolioFollower::class)->syncPortfolio($portfolio, $scan);

        // CCC tem escore 88, abaixo da mínima; END encerra a negociação.
        $this->assertSame(['AAAUSDT'], $this->tracked($portfolio));
    }

    public function test_nota_minima_vale_para_o_escore_de_rompimento(): void
    {
        config(['explorer.follow.min_score' => 60]);
        $portfolio = $this->following();
        $scan = $this->scan(['AAAUSDT', 'BBBUSDT']);
        // A nota antiga é alta nos dois, mas BBB não tem chance de rompimento.
        $scan->opportunities()->where('symbol', 'BBBUSDT')->update(['breakout_score' => 30]);

        app(PortfolioFollower::class)->syncPortfolio($portfolio, $scan);

        $this->assertSame(['AAAUSDT'], $this->tracked($portfolio));
        $this->assertSame('1º do explorador, rompimento 90.', PortfolioFollower::history($portfolio)[0]['reason']);

        config(['explorer.breakout.order_by' => 'score']);
        app(PortfolioFollower::class)->syncPortfolio($portfolio->refresh(), $scan);

        $this->assertSame(['AAAUSDT', 'BBBUSDT'], $this->tracked($portfolio->refresh()));
    }

    public function test_sem_nota_minima_par_sem_escore_de_rompimento_conta_pela_posicao(): void
    {
        $portfolio = $this->following();
        $scan = $this->scan(['AAAUSDT', 'NEWUSDT', 'CCCUSDT']);
        // Histórico curto: o explorador não calculou o escore de rompimento de NEW.
        $scan->opportunities()->where('symbol', 'NEWUSDT')->update(['breakout_score' => null]);

        app(PortfolioFollower::class)->syncPortfolio($portfolio, $scan);

        $this->assertSame(['AAAUSDT', 'CCCUSDT', 'NEWUSDT'], $this->tracked($portfolio));

        config(['explorer.follow.min_score' => 1]);
        $other = $this->following();
        app(PortfolioFollower::class)->syncPortfolio($other, $scan);

        $this->assertSame(['AAAUSDT', 'CCCUSDT'], $this->tracked($other));
    }

    public function test_dono_liga_e_a_ultima_varredura_vale_na_hora(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $this->scan(['AAAUSDT', 'BBBUSDT']);

        $this->actingAs($user)
            ->put(route('portfolios.explorer-follow', $portfolio), ['enabled' => true, 'size' => 20, 'min_hours' => 2])
            ->assertRedirect();

        $portfolio->refresh();
        $this->assertTrue($portfolio->explorer_follow);
        $this->assertSame(20, $portfolio->explorer_follow_size);
        $this->assertSame(['AAAUSDT', 'BBBUSDT'], $this->tracked($portfolio));

        // Seguindo, a escolha manual fica travada.
        $this->actingAs($user)
            ->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => []])
            ->assertSessionHasErrors('asset_ids');

        $this->actingAs($user)
            ->put(route('portfolios.explorer-follow', $portfolio), ['enabled' => false, 'size' => 20, 'min_hours' => 2])
            ->assertRedirect();

        $this->assertFalse($portfolio->refresh()->explorer_follow);
        $this->assertSame(['AAAUSDT', 'BBBUSDT'], $this->tracked($portfolio));
    }

    public function test_carteira_nova_comeca_desligada_e_limite_e_validado(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();

        $this->assertFalse($portfolio->refresh()->explorer_follow);
        $this->assertSame(20, $portfolio->explorer_follow_size);

        $this->actingAs($user)
            ->put(route('portfolios.explorer-follow', $portfolio), ['enabled' => true, 'size' => 500, 'min_hours' => 2])
            ->assertSessionHasErrors('size');
    }

    public function test_outro_usuario_recebe_403(): void
    {
        $portfolio = Portfolio::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('portfolios.explorer-follow', $portfolio), ['enabled' => true, 'size' => 20, 'min_hours' => 2])
            ->assertForbidden();

        $this->assertFalse($portfolio->refresh()->explorer_follow);
    }

    public function test_tela_da_carteira_mostra_a_opcao(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['explorer_follow' => true]);

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page
                ->where('explorer_follow.enabled', true)
                ->where('explorer_follow.size', 20)
                ->has('explorer_follow.history'));
    }
}
