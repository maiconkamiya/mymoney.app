<?php

namespace Tests\Feature\Agents;

use App\Enums\AgentRole;
use App\Enums\SignalStage;
use App\Enums\VoteAction;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentActivityTest extends TestCase
{
    use RefreshDatabase;

    private function signal(Portfolio $portfolio, SignalStage $stage, string $side = 'buy'): Signal
    {
        return Signal::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => Asset::factory()->create()->id,
            'stage' => $stage,
            'side' => $side,
        ]);
    }

    private function vote(Signal $signal, AgentRole $agent, VoteAction $action, int $round = 1, bool $veto = false): void
    {
        $signal->votes()->create([
            'agent' => $agent,
            'round' => $round,
            'action' => $action,
            'confidence' => 0.7,
            'veto' => $veto,
            'reasons' => ['Motivo de teste.'],
        ]);
    }

    public function test_portfolio_without_cycles_is_empty_but_lists_every_agent()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();

        $this->actingAs($user)->getJson(route('portfolios.agents', $portfolio))
            ->assertOk()
            ->assertJsonPath('status', 'empty')
            ->assertJsonPath('signal', null)
            ->assertJsonCount(count(AgentRole::cases()), 'agents')
            ->assertJsonPath('messages', []);
    }

    public function test_scanned_signal_shows_the_voters_analyzing()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $this->signal($portfolio, SignalStage::Scanned);

        $response = $this->actingAs($user)->getJson(route('portfolios.agents', $portfolio))->assertOk();

        $response->assertJsonPath('status', 'live');
        $states = collect($response->json('agents'))->pluck('state', 'role');
        $this->assertSame('analyzing', $states['technical']);
        $this->assertSame('analyzing', $states['coordinator']);
        $this->assertSame('idle', $states['executor']);
    }

    public function test_approved_signal_maps_votes_to_stances_and_messages()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $signal = $this->signal($portfolio, SignalStage::Approved);
        $this->vote($signal, AgentRole::Technical, VoteAction::Buy);
        $this->vote($signal, AgentRole::News, VoteAction::Sell);
        $this->vote($signal, AgentRole::Quant, VoteAction::Hold);
        $this->vote($signal, AgentRole::Risk, VoteAction::Buy, veto: true);
        $this->vote($signal, AgentRole::Skeptic, VoteAction::Hold, round: 2);

        $response = $this->actingAs($user)->getJson(route('portfolios.agents', $portfolio))->assertOk();

        $states = collect($response->json('agents'))->pluck('state', 'role');
        $this->assertSame('approved', $states['technical']);
        $this->assertSame('rejected', $states['news']);
        $this->assertSame('neutral', $states['quant']);
        $this->assertSame('veto', $states['risk']);
        $this->assertSame('approved', $states['coordinator']);
        $this->assertSame('waiting', $states['executor']);

        $kinds = collect($response->json('messages'))->pluck('kind');
        $this->assertContains('challenge', $kinds);
        $this->assertContains('veto', $kinds);
        $this->assertSame('decision', $kinds->last());
    }

    public function test_old_cycle_is_reported_as_idle()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $signal = $this->signal($portfolio, SignalStage::Rejected);
        $signal->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $this->actingAs($user)->getJson(route('portfolios.agents', $portfolio))
            ->assertJsonPath('status', 'idle')
            ->assertJsonPath('signal.stage', 'rejected');
    }

    public function test_other_users_cannot_read_the_activity()
    {
        $portfolio = Portfolio::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson(route('portfolios.agents', $portfolio))
            ->assertForbidden();
    }

    public function test_portfolio_page_receives_the_first_snapshot()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolios/Show')
                ->where('agent_activity.status', 'empty'));
    }
}
