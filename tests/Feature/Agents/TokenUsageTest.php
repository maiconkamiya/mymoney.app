<?php

namespace Tests\Feature\Agents;

use App\Agents\TokenUsage;
use App\Enums\AgentRole;
use App\Enums\SignalStage;
use App\Enums\VoteAction;
use App\Events\PortfolioChanged;
use App\Models\AgentVote;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TokenUsageTest extends TestCase
{
    use RefreshDatabase;

    private function signal(Portfolio $portfolio): Signal
    {
        return Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => Asset::factory()->create()->id,
            'stage' => SignalStage::Rejected,
        ]);
    }

    private function vote(Signal $signal, string $at, int $input, int $output, float $cost = 0): AgentVote
    {
        $vote = new AgentVote;
        $vote->forceFill([
            'signal_id' => $signal->id,
            'agent' => AgentRole::Technical,
            'round' => 1,
            'action' => VoteAction::Hold,
            'confidence' => 0.5,
            'veto' => false,
            'abstained' => $input === 0,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost' => $cost,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        return $vote;
    }

    public function test_soma_hoje_semana_mes_e_projeta_o_mes_so_com_os_votos_da_carteira()
    {
        $this->travelTo('2026-10-18 12:00:00');
        $portfolio = Portfolio::factory()->create();
        $signal = $this->signal($portfolio);

        $this->vote($signal, '2026-10-18 09:00:00', 1000, 200, 0.01);
        $this->vote($signal, '2026-10-18 10:00:00', 0, 0); // voto reaproveitado ou abstenção: não é chamada
        $this->vote($signal, '2026-10-13 10:00:00', 3000, 500, 0.02);
        $this->vote($signal, '2026-10-05 10:00:00', 6000, 300, 0.03);
        $this->vote($signal, '2026-09-30 23:00:00', 9999, 9999, 1);
        $this->vote($this->signal(Portfolio::factory()->create()), '2026-10-18 09:00:00', 50000, 5000, 5);

        $usage = app(TokenUsage::class)->for($portfolio);

        $this->assertSame(['input' => 1000, 'output' => 200, 'total' => 1200, 'cost' => 0.01, 'calls' => 1], $usage['today']);
        $this->assertSame(4700, $usage['week']['total']);
        $this->assertSame(2, $usage['week']['calls']);
        $this->assertSame(11000, $usage['month']['total']);
        $this->assertSame(10000, $usage['month']['input']);
        // 18 dias contados (1º a 18), 13 pela frente: 11000 + 11000 / 18 × 13.
        $this->assertSame(18, $usage['estimate']['days']);
        $this->assertSame(31, $usage['estimate']['days_in_month']);
        $this->assertSame(18944, $usage['estimate']['total']);
        $this->assertSame('2026-09-30T23:00:00+00:00', $usage['since']);
    }

    public function test_carteira_que_comecou_no_meio_do_mes_projeta_pela_media_dos_dias_contados()
    {
        $this->travelTo('2026-10-18 12:00:00');
        $portfolio = Portfolio::factory()->create();
        $this->vote($this->signal($portfolio), '2026-10-15 08:00:00', 800, 200);

        $usage = app(TokenUsage::class)->for($portfolio);

        // 4 dias contados (15 a 18), média 250 por dia nos 13 restantes.
        $this->assertSame(4, $usage['estimate']['days']);
        $this->assertSame(4250, $usage['estimate']['total']);
    }

    public function test_carteira_sem_chamadas_mostra_zero_e_sem_data_de_inicio()
    {
        $usage = app(TokenUsage::class)->for(Portfolio::factory()->create());

        $this->assertNull($usage['since']);
        $this->assertSame(0, $usage['month']['total']);
        $this->assertSame(0, $usage['estimate']['total']);
    }

    public function test_voto_novo_avisa_o_canal_da_carteira_com_o_escopo_de_tokens()
    {
        $portfolio = Portfolio::factory()->create();
        $signal = $this->signal($portfolio);
        Event::fake([PortfolioChanged::class]);

        $signal->votes()->create([
            'agent' => AgentRole::Risk,
            'round' => 1,
            'action' => VoteAction::Buy,
            'confidence' => 0.7,
            'input_tokens' => 1200,
            'output_tokens' => 300,
        ]);

        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => $event->portfolioId === $portfolio->id
            && $event->scopes === [PortfolioChanged::SCOPE_TOKENS]);
    }

    public function test_tela_da_carteira_entrega_os_tokens_e_recarga_parcial_traz_so_eles()
    {
        $this->travelTo('2026-10-18 12:00:00');
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $this->vote($this->signal($portfolio), '2026-10-18 09:00:00', 1000, 500);

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolios/Show')
                ->where('token_usage.today.total', 1500)
                ->where('token_usage.estimate.days', 1));

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('token_usage', fn (Assert $reload) => $reload
                    ->where('token_usage.month.total', 1500)
                    ->missing('signals')));
    }

    public function test_outra_conta_nao_ve_os_tokens_da_carteira()
    {
        $portfolio = Portfolio::factory()->create();
        $this->vote($this->signal($portfolio), now()->toDateTimeString(), 1000, 500);

        $this->actingAs(User::factory()->create())
            ->get(route('portfolios.show', $portfolio))
            ->assertForbidden();
    }
}
