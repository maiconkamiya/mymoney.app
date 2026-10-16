<?php

namespace Tests\Feature\Agents;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\SignalScanner;
use App\Events\AgentActivityHappened;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class AgentLiveEventsTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private function runCycle(string $action, bool $autoExecute = false): Portfolio
    {
        $this->app->instance(LlmClient::class, new FakeLlmClient(fn () => [
            'action' => $action, 'confidence' => 0.8, 'veto' => false,
            'reasons' => ['teste'], 'evidence_ids' => [], 'stop' => 95.0, 'target' => 110.0,
        ]));
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        $portfolio = Portfolio::factory()->create(['market' => $asset->market, 'auto_execute' => $autoExecute]);

        app(SignalScanner::class)->scan($asset);

        return $portfolio;
    }

    public function test_the_cycle_is_announced_step_by_step_as_it_happens()
    {
        Event::fake([AgentActivityHappened::class]);

        $portfolio = $this->runCycle('buy');

        $sent = Event::dispatched(AgentActivityHappened::class)->map(fn (array $args) => $args[0]);
        $types = $sent->pluck('type');

        $this->assertSame('cycle.started', $types->first());
        $this->assertSame('consensus.decided', $types->last());
        // 5 first-round voters + the skeptic + 5 revisions, each with a start and a vote.
        $this->assertSame(11, $types->filter(fn ($type) => $type === 'agent.started')->count());
        $this->assertSame(11, $types->filter(fn ($type) => $type === 'agent.voted')->count());
        // The skeptic speaks after the first round and before the revisions.
        $roles = $sent->where('type', 'agent.started')->pluck('payload.role')->values();
        $this->assertSame('skeptic', $roles[5]);
        $this->assertSame($portfolio->id, $sent->first()->portfolioId);
        $this->assertSame('consensus.decided', $sent->last()->type);
        $this->assertSame('approved', collect($sent->last()->payload['snapshot']['agents'])->firstWhere('role', 'coordinator')['state']);
    }

    public function test_an_auto_executed_order_is_announced_by_the_executor()
    {
        Event::fake([AgentActivityHappened::class]);

        $this->runCycle('buy', autoExecute: true);

        $types = Event::dispatched(AgentActivityHappened::class)->map(fn (array $args) => $args[0]->type);

        $this->assertContains('order.started', $types);
        $this->assertSame('order.finished', $types->last());
    }

    public function test_events_go_to_the_portfolios_private_channel_with_a_stable_name()
    {
        $event = new AgentActivityHappened(7, 'agent.started', ['role' => 'news']);

        $this->assertEquals([new PrivateChannel('portfolios.7.agents')], $event->broadcastOn());
        $this->assertSame('agent.activity', $event->broadcastAs());
        $this->assertSame('news', $event->broadcastWith()['role']);
        $this->assertSame('agent.started', $event->broadcastWith()['type']);
    }

    public function test_a_broken_socket_server_does_not_stop_the_agents()
    {
        Event::listen(AgentActivityHappened::class, fn () => throw new \RuntimeException('Reverb fora do ar'));

        $portfolio = $this->runCycle('buy');

        $this->assertSame('approved', $portfolio->signals()->sole()->stage->value);
    }

    public function test_only_the_owner_may_listen_to_a_portfolios_channel()
    {
        $owner = User::factory()->create();
        $portfolio = Portfolio::factory()->for($owner)->create();
        $name = 'portfolios.'.$portfolio->id.'.agents';

        // The test environment broadcasts to "null", which authorizes everything; use the real driver here.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'chave',
            'broadcasting.connections.reverb.secret' => 'segredo',
            'broadcasting.connections.reverb.app_id' => 'app',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');

        $this->actingAs($owner)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-'.$name])
            ->assertSuccessful();
        $this->actingAs(User::factory()->create())->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-'.$name])
            ->assertForbidden();
    }
}
