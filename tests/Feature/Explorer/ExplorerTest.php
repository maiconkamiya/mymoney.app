<?php

namespace Tests\Feature\Explorer;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Agents\SignalScanner;
use App\Console\Commands\ExploreOpportunities;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Explorer\OpportunityExplorer;
use App\Explorer\OpportunityRanker;
use App\Jobs\ExplorerSwarmJob;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\Opportunity;
use App\Models\OpportunityScan;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function ticker(string $symbol, float $change, float $volume, float $price = 1.0, float $spreadPct = 0.05): array
    {
        $half = $price * $spreadPct / 200;

        return [
            'symbol' => $symbol,
            'lastPrice' => (string) $price,
            'priceChangePercent' => (string) $change,
            'quoteVolume' => (string) $volume,
            'bidPrice' => (string) ($price - $half),
            'askPrice' => (string) ($price + $half),
            'highPrice' => (string) ($price * 1.01),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function symbolRow(string $symbol, string $base): array
    {
        return [
            'symbol' => $symbol,
            'status' => 'TRADING',
            'baseAsset' => $base,
            'quoteAsset' => 'USDT',
            'isSpotTradingAllowed' => true,
            'filters' => [
                ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.00010000'],
                ['filterType' => 'LOT_SIZE', 'minQty' => '0.10000000', 'stepSize' => '0.10000000'],
                ['filterType' => 'NOTIONAL', 'minNotional' => '5.00000000'],
            ],
        ];
    }

    /**
     * 48 velas de 1 h subindo devagar; a penúltima (última fechada) com volume `$lastVolume`
     * e a hora em curso fechando em `$price`.
     *
     * @return list<list<mixed>>
     */
    private function hourly(float $price, float $lastVolume, float $step = 0.001): array
    {
        $rows = [];
        $start = now()->subHours(47)->startOfHour()->getTimestampMs();

        for ($i = 0; $i < 48; $i++) {
            $close = $price * (1 - $step * (47 - $i));
            $rows[] = [$start + $i * 3_600_000, (string) ($close * 0.998), (string) ($close * 1.004), (string) ($close * 0.994), (string) $close, $i === 46 ? (string) $lastVolume : '100'];
        }

        return $rows;
    }

    private function fakeBinance(): void
    {
        Http::fake([
            'data-api.binance.vision/api/v3/exchangeInfo*' => Http::response(['symbols' => [
                $this->symbolRow('ROSEUSDT', 'ROSE'),
                $this->symbolRow('KAVAUSDT', 'KAVA'),
                $this->symbolRow('USDCUSDT', 'USDC'),
                $this->symbolRow('BTCUSDT', 'BTC'),
                $this->symbolRow('BTCUPUSDT', 'BTCUP'),
                $this->symbolRow('SYRUPUSDT', 'SYRUP'),
                $this->symbolRow('THINUSDT', 'THIN'),
                $this->symbolRow('WIDEUSDT', 'WIDE'),
                $this->symbolRow('HOTUSDT', 'HOT'),
            ]]),
            'data-api.binance.vision/api/v3/ticker/24hr*' => Http::response([
                $this->ticker('ROSEUSDT', 6.0, 30_000_000, 0.1),
                $this->ticker('KAVAUSDT', 3.0, 5_000_000, 0.5),
                $this->ticker('USDCUSDT', 2.5, 900_000_000, 1.0),
                $this->ticker('BTCUSDT', 1.0, 900_000_000, 60_000),
                $this->ticker('BTCUPUSDT', 9.0, 50_000_000, 10),
                $this->ticker('SYRUPUSDT', 4.0, 8_000_000, 0.3),
                $this->ticker('THINUSDT', 6.0, 100_000, 1.0),
                $this->ticker('WIDEUSDT', 6.0, 20_000_000, 1.0, 0.8),
                $this->ticker('HOTUSDT', 40.0, 80_000_000, 1.0),
                $this->ticker('ETHBTC', 5.0, 900_000_000, 0.05),
            ]),
            'data-api.binance.vision/api/v3/klines*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return match ($query['symbol'] ?? '') {
                    // Volume da última hora 4x a média e preço acima da máxima de 24 h.
                    'ROSEUSDT' => Http::response($this->hourly(0.1, 400)),
                    // Sem aceleração.
                    'KAVAUSDT' => Http::response($this->hourly(0.5, 100)),
                    // Subida vertical: muito acima da EMA21 em ATR.
                    'SYRUPUSDT' => Http::response($this->hourly(0.3, 100, 0.02)),
                    default => Http::response($this->hourly(1, 100)),
                };
            },
        ]);
    }

    public function test_filtro_barato_tira_estaveis_alavancados_iliquidos_spread_largo_e_alta_esticada(): void
    {
        $bases = ['ROSEUSDT' => 'ROSE', 'USDCUSDT' => 'USDC', 'BTCUSDT' => 'BTC', 'BTCUPUSDT' => 'BTCUP', 'SYRUPUSDT' => 'SYRUP', 'THINUSDT' => 'THIN', 'WIDEUSDT' => 'WIDE', 'HOTUSDT' => 'HOT'];
        $tickers = array_map(fn (array $row) => [
            'symbol' => $row['symbol'], 'price' => (float) $row['lastPrice'], 'change_pct' => (float) $row['priceChangePercent'],
            'quote_volume' => (float) $row['quoteVolume'], 'bid' => (float) $row['bidPrice'], 'ask' => (float) $row['askPrice'], 'high' => (float) $row['highPrice'],
        ], [
            $this->ticker('ROSEUSDT', 6.0, 30_000_000),
            $this->ticker('USDCUSDT', 2.5, 900_000_000),
            $this->ticker('BTCUSDT', 1.0, 900_000_000),
            $this->ticker('BTCUPUSDT', 9.0, 50_000_000),
            $this->ticker('SYRUPUSDT', 4.0, 8_000_000),
            $this->ticker('THINUSDT', 6.0, 100_000),
            $this->ticker('WIDEUSDT', 6.0, 20_000_000, 1.0, 0.8),
            $this->ticker('HOTUSDT', 40.0, 80_000_000),
            $this->ticker('ETHBTC', 5.0, 900_000_000),
        ]);

        $passed = array_column((new OpportunityRanker)->filter($tickers, $bases), 'symbol');

        // SYRUP termina em UP mas "SYR" não é moeda listada: não é token alavancado.
        $this->assertSame(['ROSEUSDT', 'SYRUPUSDT'], $passed);
    }

    public function test_nota_premia_volume_acelerando_e_rompimento_e_descarta_alta_esticada(): void
    {
        $ranker = new OpportunityRanker;
        $pair = fn (string $symbol, float $price) => ['symbol' => $symbol, 'base' => 'X', 'price' => $price, 'change_pct' => 6.0, 'quote_volume' => 30_000_000, 'spread_pct' => 0.05, 'prelim' => 0.5];
        $bars = fn (array $rows) => array_map(fn (array $r) => ['open' => (float) $r[1], 'high' => (float) $r[2], 'low' => (float) $r[3], 'close' => (float) $r[4], 'volume' => (float) $r[5]], $rows);

        $hot = $ranker->score($pair('ROSEUSDT', 0.1), $bars($this->hourly(0.1, 400)));
        $calm = $ranker->score($pair('KAVAUSDT', 0.1), $bars($this->hourly(0.1, 100)));

        $this->assertNotNull($hot);
        $this->assertNotNull($calm);
        $this->assertEqualsWithDelta(4.0, $hot['volume_accel'], 0.01);
        $this->assertGreaterThan($calm['score'], $hot['score']);
        $this->assertStringContainsString('Volume da última hora 4,0x', implode(' ', $hot['reasons']));

        $this->assertNull($ranker->score($pair('SYRUPUSDT', 0.3), $bars($this->hourly(0.3, 100, 0.02))));
        $this->assertNull($ranker->score($pair('NEWUSDT', 0.3), $bars(array_slice($this->hourly(0.3, 100), 0, 10))), 'Sem 24 h de histórico não há nota.');
    }

    public function test_varredura_salva_o_ranking_com_explicacao_da_ia_sem_mandar_aos_agentes(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $fake = new FakeLlmClient(fn () => ['items' => [
            ['symbol' => 'KAVAUSDT', 'note' => 'Alta firme com volume normal.'],
            ['symbol' => 'INVENTADOUSDT', 'note' => 'Não existe.'],
            ['symbol' => 'ROSEUSDT', 'note' => 'Volume quatro vezes a média e rompeu a máxima.'],
        ]]);
        $this->app->instance(LlmClient::class, $fake);
        Portfolio::factory()->create(); // carteira sem envio automático

        ['scan' => $scan, 'swarmed' => $swarmed] = app(OpportunityExplorer::class)->run();

        $this->assertNull($scan->error);
        $this->assertSame(10, $scan->pairs_seen);
        $this->assertSame(3, $scan->pairs_passed); // ROSE, KAVA, SYRUP
        $this->assertSame(['KAVAUSDT', 'ROSEUSDT'], $scan->opportunities->pluck('symbol')->all(), 'SYRUP saiu por estar esticado; a ordem é a da IA.');
        $this->assertSame([1, 2], $scan->opportunities->pluck('rank')->all());
        $this->assertSame('Alta firme com volume normal.', $scan->opportunities[0]->ai_note);
        $this->assertCount(1, $fake->calls);
        $this->assertSame([], $swarmed);
        $this->assertDatabaseCount('assets', 0);
        Queue::assertNothingPushed();
    }

    public function test_sem_resposta_valida_da_ia_fica_a_ordem_da_nota(): void
    {
        Queue::fake();
        $this->fakeBinance();

        ['scan' => $scan] = app(OpportunityExplorer::class)->run();

        $this->assertSame(['ROSEUSDT', 'KAVAUSDT'], $scan->opportunities->pluck('symbol')->all());
        $this->assertNull($scan->opportunities[0]->ai_note);
        $this->assertNotEmpty($scan->opportunities[0]->reasons);
    }

    public function test_binance_fora_do_ar_registra_o_erro(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('explorer:scan', ['--force' => true])->assertFailed();

        $this->assertStringContainsString('Binance indisponível', (string) OpportunityScan::query()->sole()->error);
    }

    public function test_envio_automatico_vai_so_para_carteiras_que_ligaram_e_respeita_o_intervalo(): void
    {
        Queue::fake();
        $this->fakeBinance();
        config(['explorer.swarm.min_score' => 0]);

        $on = Portfolio::factory()->create(['explorer_auto_swarm' => true]);
        Portfolio::factory()->create(['explorer_auto_swarm' => false]);
        Portfolio::factory()->create(['explorer_auto_swarm' => true, 'status' => PortfolioStatus::Paused]);

        ['swarmed' => $swarmed] = app(OpportunityExplorer::class)->run();

        $this->assertSame(['ROSEUSDT', 'KAVAUSDT'], $swarmed);
        Queue::assertPushed(ExplorerSwarmJob::class, 2);
        Queue::assertPushed(ExplorerSwarmJob::class, fn (ExplorerSwarmJob $job) => $job->portfolioId === $on->id && str_contains($job->reason, 'Explorador'));

        $rose = Asset::query()->where('symbol', 'ROSEUSDT')->sole();
        $this->assertTrue($rose->discovered);
        $this->assertTrue($rose->active);
        $this->assertDatabaseHas('audit_log', ['action' => 'explorer.swarm', 'actor' => 'explorer']);

        // Na varredura seguinte os mesmos pares ainda estão no intervalo de 6 h.
        ['swarmed' => $again] = app(OpportunityExplorer::class)->run();
        $this->assertSame([], $again);
        Queue::assertPushed(ExplorerSwarmJob::class, 2);
    }

    public function test_envio_automatico_respeita_nota_minima_e_limite_do_dia(): void
    {
        Queue::fake();
        $this->fakeBinance();
        Portfolio::factory()->create(['explorer_auto_swarm' => true]);
        config(['explorer.swarm.min_score' => 0, 'explorer.swarm.max_per_day' => 1]);

        $this->assertSame(['ROSEUSDT'], app(OpportunityExplorer::class)->run()['swarmed']);

        config(['explorer.swarm.max_per_day' => 10, 'explorer.swarm.min_score' => 101]);
        Opportunity::query()->update(['swarm_sent_at' => null]);

        $this->assertSame([], app(OpportunityExplorer::class)->run()['swarmed']);
    }

    public function test_par_ja_acompanhado_nao_gasta_envio_extra(): void
    {
        Queue::fake();
        $this->fakeBinance();
        config(['explorer.swarm.min_score' => 0]);
        $rose = Asset::factory()->create(['symbol' => 'ROSEUSDT']);
        $kava = Asset::factory()->create(['symbol' => 'KAVAUSDT']);
        $portfolio = Portfolio::factory()->create(['explorer_auto_swarm' => true]);
        $portfolio->assets()->sync([$rose->id]);

        $this->assertSame(['KAVAUSDT'], app(OpportunityExplorer::class)->run()['swarmed']);
        Queue::assertPushed(ExplorerSwarmJob::class, fn (ExplorerSwarmJob $job) => $job->assetId === $kava->id);
        $this->assertFalse($kava->fresh()->discovered, 'Um par cadastrado à mão continua cadastrado à mão.');
    }

    public function test_par_achado_nao_entra_no_acompanha_todos_e_sai_sozinho(): void
    {
        Queue::fake();
        $found = Asset::factory()->create(['symbol' => 'ROSEUSDT', 'discovered' => true]);
        $manual = Asset::factory()->create(['symbol' => 'SOLUSDT']);
        $all = Portfolio::factory()->create();
        $chosen = Portfolio::factory()->create();
        $chosen->assets()->sync([$found->id]);

        $this->assertSame([$chosen->id], Portfolio::query()->tracking($found)->pluck('id')->all());
        $this->assertSame([$all->id], Portfolio::query()->tracking($manual)->pluck('id')->all());

        foreach ([$found, $manual] as $asset) {
            Candle::create(['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute(), 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
        }

        $this->assertSame([$chosen->id], array_map(fn (Signal $s) => $s->portfolio_id, app(SignalScanner::class)->scan($found)));

        // Fora do topo há mais de 24 h e sem carteira acompanhando: sai.
        $stale = Asset::factory()->create(['symbol' => 'OLDUSDT', 'discovered' => true]);
        Asset::query()->whereKey($stale->id)->update(['updated_at' => now()->subDays(2)]);
        Asset::query()->whereKey($found->id)->update(['updated_at' => now()->subDays(2)]);
        $this->fakeBinance();

        app(OpportunityExplorer::class)->run();

        $this->assertDatabaseMissing('assets', ['id' => $stale->id]);
        $this->assertDatabaseHas('assets', ['id' => $found->id, 'active' => true]); // a carteira acompanha
    }

    public function test_job_traz_velas_e_abre_sinal_na_carteira_que_nao_acompanha_o_par(): void
    {
        Queue::fake();
        $asset = Asset::factory()->create(['symbol' => 'ROSEUSDT', 'discovered' => true]);
        $portfolio = Portfolio::factory()->create();
        $other = Asset::factory()->create();
        $portfolio->assets()->sync([$other->id]);

        Http::fake(['data-api.binance.vision/api/v3/klines*' => function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) $query['startTime'] > now()->subMinutes(5)->getTimestampMs()
                ? Http::response([])
                : Http::response([[now()->startOfMinute()->getTimestampMs(), '0.1', '0.1', '0.1', '0.1', '10']]);
        }]);

        (new ExplorerSwarmJob($portfolio->id, $asset->id, 'Explorador: 1º da varredura, nota 70.'))->handle(app(SignalScanner::class));

        $signal = Signal::query()->sole();
        $this->assertSame($portfolio->id, $signal->portfolio_id);
        $this->assertSame('Explorador: 1º da varredura, nota 70.', $signal->stage_history[0]['reason']);
        $this->assertTrue(Candle::query()->where('asset_id', $asset->id)->exists());
    }

    public function test_dono_acompanha_oportunidade_na_carteira(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        $existing = Asset::factory()->create();
        $portfolio->assets()->sync([$existing->id]);
        $opportunity = $this->opportunity('ROSEUSDT');

        $this->actingAs($user)->post(route('opportunities.track', $opportunity), ['portfolio_id' => $portfolio->id])->assertRedirect();

        $rose = Asset::query()->where('symbol', 'ROSEUSDT')->sole();
        $this->assertFalse($rose->discovered);
        $this->assertEqualsCanonicalizing([$existing->id, $rose->id], $portfolio->assets()->pluck('assets.id')->all());
        $this->assertDatabaseHas('audit_log', ['action' => 'explorer.track', 'actor' => 'user:'.$user->id]);
    }

    public function test_acompanhar_numa_carteira_que_acompanha_todos_nao_restringe_a_escolha(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();
        Asset::factory()->create(['symbol' => 'ROSEUSDT', 'discovered' => true]);

        $this->actingAs($user)->post(route('opportunities.track', $this->opportunity('ROSEUSDT')), ['portfolio_id' => $portfolio->id])->assertRedirect();

        $this->assertSame(0, $portfolio->assets()->count());
        $this->assertFalse(Asset::query()->where('symbol', 'ROSEUSDT')->sole()->discovered);
    }

    public function test_dono_manda_oportunidade_aos_agentes(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create(['mode' => PortfolioMode::Paper]);
        $opportunity = $this->opportunity('ROSEUSDT');

        $this->actingAs($user)->post(route('opportunities.swarm', $opportunity), ['portfolio_id' => $portfolio->id])->assertRedirect();

        Queue::assertPushed(ExplorerSwarmJob::class, fn (ExplorerSwarmJob $job) => $job->portfolioId === $portfolio->id);
        $this->assertNotNull($opportunity->fresh()->swarm_sent_at);
        $this->assertTrue(Asset::query()->where('symbol', 'ROSEUSDT')->sole()->discovered);

        $paused = Portfolio::factory()->for($user)->create(['status' => PortfolioStatus::Paused]);
        $this->actingAs($user)->post(route('opportunities.swarm', $opportunity), ['portfolio_id' => $paused->id])->assertSessionHasErrors('portfolio_id');
    }

    public function test_dono_liga_o_envio_automatico(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->for($user)->create();

        $this->actingAs($user)->put(route('portfolios.explorer', $portfolio), ['enabled' => true])->assertRedirect();
        $this->assertTrue($portfolio->fresh()->explorer_auto_swarm);

        $this->actingAs($user)->put(route('portfolios.explorer', $portfolio), ['enabled' => false])->assertRedirect();
        $this->assertFalse($portfolio->fresh()->explorer_auto_swarm);
    }

    public function test_outro_usuario_recebe_403_nas_acoes_do_explorador(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $portfolio = Portfolio::factory()->create();
        $intruder = User::factory()->create();
        $opportunity = $this->opportunity('ROSEUSDT');

        $this->actingAs($intruder)->post(route('opportunities.track', $opportunity), ['portfolio_id' => $portfolio->id])->assertForbidden();
        $this->actingAs($intruder)->post(route('opportunities.swarm', $opportunity), ['portfolio_id' => $portfolio->id])->assertForbidden();
        $this->actingAs($intruder)->put(route('portfolios.explorer', $portfolio), ['enabled' => true])->assertForbidden();

        $this->assertFalse($portfolio->fresh()->explorer_auto_swarm);
        $this->assertDatabaseCount('assets', 0);
        Queue::assertNothingPushed();
    }

    public function test_tela_de_ativos_mostra_a_ultima_varredura_e_so_as_carteiras_do_usuario(): void
    {
        $user = User::factory()->create();
        $mine = Portfolio::factory()->for($user)->create(['name' => 'Minha']);
        Portfolio::factory()->create(['name' => 'De outro']);
        Asset::factory()->create(['symbol' => 'ROSEUSDT', 'discovered' => true]);
        $this->opportunity('ROSEUSDT');

        $this->actingAs($user)->get(route('assets.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Assets')
                ->where('explorer.opportunities.0.symbol', 'ROSEUSDT')
                ->where('explorer.opportunities.0.status', 'discovered')
                ->where('explorer.portfolios', [['id' => $mine->id, 'name' => 'Minha', 'mode' => 'paper', 'running' => true, 'auto_swarm' => false]])
                ->where('assets.0.discovered', true));
    }

    public function test_horario_da_varredura(): void
    {
        $this->assertTrue(ExploreOpportunities::withinHours('', 3));
        $this->assertTrue(ExploreOpportunities::withinHours('8-22', 8));
        $this->assertTrue(ExploreOpportunities::withinHours('8-22', 22));
        $this->assertFalse(ExploreOpportunities::withinHours('8-22', 23));
        $this->assertTrue(ExploreOpportunities::withinHours('22-6', 2));
        $this->assertFalse(ExploreOpportunities::withinHours('22-6', 12));
    }

    public function test_comando_respeita_o_explorador_desligado(): void
    {
        Http::fake();
        config(['explorer.enabled' => false]);

        $this->artisan('explorer:scan')->assertSuccessful();

        $this->assertDatabaseCount('opportunity_scans', 0);
        Http::assertNothingSent();
    }

    private function opportunity(string $symbol): Opportunity
    {
        $scan = OpportunityScan::create(['pairs_seen' => 10, 'pairs_passed' => 1]);

        return $scan->opportunities()->create([
            'symbol' => $symbol, 'base' => str_replace('USDT', '', $symbol), 'rank' => 1, 'score' => 70, 'price' => 0.1,
            'change_24h_pct' => 6, 'quote_volume' => 30_000_000, 'spread_pct' => 0.05, 'volume_accel' => 4, 'breakout' => true,
            'extension_atr' => 1.2, 'reasons' => ['Alta de 6,0% em 24 h.'],
        ]);
    }
}
