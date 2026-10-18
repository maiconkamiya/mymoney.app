<?php

namespace Tests\Feature\Agents;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\SignalPipeline;
use App\Agents\SignalScanner;
use App\Enums\SignalStage;
use App\Events\PortfolioChanged;
use App\Jobs\AnalyzeSignalJob;
use App\Jobs\TriageNewsJob;
use App\Models\Asset;
use App\Models\NewsItem;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use App\News\NewsTriage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsMarket;
use Tests\Concerns\ProvidesAgentInputs;
use Tests\TestCase;

/**
 * "Agentes em ação" em Configurações > Custos e serviços: desligada, o enxame
 * não abre nem analisa sinal, e a carteira esconde os cards dele.
 */
class AgentsSwitchTest extends TestCase
{
    use BuildsMarket, ProvidesAgentInputs, RefreshDatabase;

    private function tradableAsset(): Asset
    {
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->history($asset, 100);
        $this->quote($asset, 100);
        $this->provideAgentInputs($asset);

        return $asset;
    }

    public function test_comeca_ligada()
    {
        $this->assertTrue(config('agents.enabled'));
    }

    public function test_desligada_a_varredura_e_o_envio_por_carteira_nao_abrem_sinal()
    {
        Queue::fake();
        $asset = $this->tradableAsset();
        $portfolio = Portfolio::factory()->create(['market' => $asset->market]);
        config(['agents.enabled' => false]);

        $this->assertSame([], app(SignalScanner::class)->scan($asset));
        $this->assertNull(app(SignalScanner::class)->scanPortfolio($portfolio, $asset));
        $this->assertSame(0, Signal::count());
        Queue::assertNotPushed(AnalyzeSignalJob::class);

        config(['agents.enabled' => true]);
        $this->assertCount(1, app(SignalScanner::class)->scan($asset));
    }

    public function test_sinal_que_esperava_na_fila_e_rejeitado_sem_chamar_o_modelo()
    {
        Queue::fake();
        $fake = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $fake);
        $asset = $this->tradableAsset();
        Portfolio::factory()->create(['market' => $asset->market]);
        $signal = app(SignalScanner::class)->scan($asset)[0];

        config(['agents.enabled' => false]);
        app(SignalPipeline::class)->analyze($signal->fresh());

        $this->assertSame(SignalStage::Rejected, $signal->fresh()->stage);
        $this->assertSame([], $fake->calls);
    }

    public function test_desligada_a_triagem_de_noticias_nao_chama_o_haiku()
    {
        $fake = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $fake);
        $news = NewsItem::create([
            'source' => 'rss', 'url' => 'https://example.com/a', 'url_hash' => NewsItem::hashUrl('https://example.com/a'),
            'title' => 'ETF de bitcoin aprovado', 'fetched_at' => now(),
        ]);
        config(['agents.enabled' => false]);

        (new TriageNewsJob($news->id))->handle(app(NewsTriage::class), app(SignalScanner::class));

        $this->assertSame([], $fake->calls);
        $this->assertNull($news->fresh()->triaged_at);
    }

    public function test_salvar_a_chave_avisa_as_carteiras_e_esconde_os_cards()
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        Event::fake([PortfolioChanged::class]);

        $this->actingAs($user)->put(route('system-settings.update'), ['agents__enabled' => false])->assertSessionHasNoErrors();

        $this->assertFalse(config('agents.enabled'));
        Event::assertDispatched(PortfolioChanged::class, fn (PortfolioChanged $event) => $event->portfolioId === $portfolio->id
            && $event->scopes === [PortfolioChanged::SCOPE_SWARM]);

        $this->actingAs($user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('agents_enabled', false)
            ->reloadOnly('agents_enabled', fn (Assert $reload) => $reload->where('agents_enabled', false)->missing('signals')));

        $this->actingAs($user)->get(route('assets.index'))->assertInertia(fn (Assert $page) => $page
            ->where('explorer.agents_enabled', false));
    }
}
