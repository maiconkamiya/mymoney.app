<?php

namespace Tests\Feature\Ativos;

use App\Agents\SignalScanner;
use App\Enums\Market;
use App\Enums\SignalStage;
use App\Jobs\AnalyzeSignalJob;
use App\Jobs\BackfillAssetCandlesJob;
use App\MarketData\TradeStream;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Candle;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AtivosTest extends TestCase
{
    use RefreshDatabase;

    private function symbolRow(string $symbol, string $base, string $status = 'TRADING', string $quote = 'USDT', bool $spot = true): array
    {
        return [
            'symbol' => $symbol,
            'status' => $status,
            'baseAsset' => $base,
            'quoteAsset' => $quote,
            'isSpotTradingAllowed' => $spot,
            'filters' => [
                ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.00010000'],
                ['filterType' => 'LOT_SIZE', 'minQty' => '0.10000000', 'stepSize' => '0.10000000'],
                ['filterType' => 'NOTIONAL', 'minNotional' => '5.00000000'],
            ],
        ];
    }

    private function fakeBinance(): void
    {
        Http::fake(['data-api.binance.vision/api/v3/exchangeInfo*' => function ($request) {
            $rows = [
                $this->symbolRow('DOGEUSDT', 'DOGE'),
                $this->symbolRow('DOGEBTC', 'DOGE', quote: 'BTC'),
                $this->symbolRow('OLDUSDT', 'OLD', status: 'BREAK'),
                $this->symbolRow('SOLUSDT', 'SOL'),
            ];

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (isset($query['symbol'])) {
                $row = collect($rows)->firstWhere('symbol', $query['symbol']);

                return $row === null
                    ? Http::response(['code' => -1121, 'msg' => 'Invalid symbol.'], 400)
                    : Http::response(['symbols' => [$row]]);
            }

            return Http::response(['symbols' => $rows]);
        }]);
    }

    public function test_pesquisa_na_binance_mostra_so_pares_usdt_negociaveis(): void
    {
        Cache::flush();
        $this->fakeBinance();
        Asset::factory()->create(['symbol' => 'SOLUSDT']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('assets.search', ['market' => 'crypto', 'q' => 'o']))
            ->assertOk();

        $this->assertSame([], $response->json('results')); // menos de 2 letras

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('assets.search', ['market' => 'crypto', 'q' => 'do']))
            ->assertOk();

        $this->assertSame(['DOGEUSDT'], array_column($response->json('results'), 'symbol'));

        $sol = $this->actingAs(User::factory()->create())
            ->getJson(route('assets.search', ['market' => 'crypto', 'q' => 'sol']))
            ->json('results.0');

        $this->assertSame('SOLUSDT', $sol['symbol']);
        $this->assertTrue($sol['registered']);
    }

    public function test_pesquisa_na_brapi_lista_papeis(): void
    {
        Http::fake(['brapi.dev/api/quote/list*' => Http::response(['stocks' => [
            ['stock' => 'MGLU3', 'name' => 'Magazine Luiza', 'type' => 'stock', 'sector' => 'Retail Trade'],
            ['stock' => 'MGLU3F', 'name' => 'Magazine Luiza', 'type' => 'stock'],
        ]])]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('assets.search', ['market' => 'b3', 'q' => 'mglu']))
            ->assertOk()
            ->assertJsonPath('results.0.symbol', 'MGLU3')
            ->assertJsonPath('results.0.registered', false);
    }

    public function test_cadastra_cripto_guardando_filtros_da_binance(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('assets.store'), ['market' => 'crypto', 'symbol' => 'dogeusdt'])->assertRedirect();

        $asset = Asset::query()->where('symbol', 'DOGEUSDT')->sole();
        $this->assertSame(Market::Crypto, $asset->market);
        $this->assertSame('USDT', $asset->quote_currency);
        $this->assertSame('0.10000000', $asset->lot_size);
        $this->assertSame('0.00010000', $asset->tick_size);
        $this->assertSame('0.10000000', $asset->min_qty);
        $this->assertSame('5.00000000', $asset->min_notional);
        $this->assertTrue($asset->active);
        $this->assertDatabaseHas('audit_log', ['action' => 'asset.created', 'actor' => 'user:'.$user->id]);
        Queue::assertPushed(BackfillAssetCandlesJob::class, fn ($job) => $job->assetId === $asset->id);
    }

    public function test_nao_cadastra_simbolo_que_a_binance_nao_negocia_em_usdt(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $user = User::factory()->create();

        foreach (['XYZUSDT', 'OLDUSDT', 'DOGEBTC', 'DOGE USDT'] as $symbol) {
            $this->actingAs($user)->post(route('assets.store'), ['market' => 'crypto', 'symbol' => $symbol])->assertSessionHasErrors('symbol');
        }

        $this->assertDatabaseCount('assets', 0);
        Queue::assertNothingPushed();
    }

    public function test_nao_cadastra_duas_vezes_e_reativa_ativo_desativado(): void
    {
        Queue::fake();
        $this->fakeBinance();
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'DOGEUSDT', 'active' => false]);

        $this->actingAs($user)->post(route('assets.store'), ['market' => 'crypto', 'symbol' => 'DOGEUSDT'])->assertSessionHasNoErrors();
        $this->assertTrue($asset->refresh()->active);

        $this->actingAs($user)->post(route('assets.store'), ['market' => 'crypto', 'symbol' => 'DOGEUSDT'])->assertSessionHasErrors('symbol');
        $this->assertDatabaseCount('assets', 1);
    }

    public function test_cadastra_papel_da_b3_confirmado_na_brapi(): void
    {
        Queue::fake();
        Http::fake([
            'brapi.dev/api/quote/MGLU3*' => Http::response(['results' => [['symbol' => 'MGLU3', 'longName' => 'Magazine Luiza S.A.', 'regularMarketPrice' => 9.5]]]),
            'brapi.dev/api/quote/ZZZZ3*' => Http::response(['error' => true, 'message' => 'Ação não encontrada'], 404),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('assets.store'), ['market' => 'b3', 'symbol' => 'MGLU3'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('assets.store'), ['market' => 'b3', 'symbol' => 'ZZZZ3'])->assertSessionHasErrors('symbol');
        $this->actingAs($user)->post(route('assets.store'), ['market' => 'b3', 'symbol' => 'PETR4USDT'])->assertSessionHasErrors('symbol');

        $asset = Asset::query()->where('symbol', 'MGLU3')->sole();
        $this->assertSame('BRL', $asset->quote_currency);
        $this->assertSame('100.00000000', $asset->lot_size);
        $this->assertSame('Magazine Luiza S.A.', $asset->name);
        $this->assertDatabaseMissing('assets', ['symbol' => 'ZZZZ3']);
    }

    public function test_falha_de_rede_na_fonte_vira_mensagem_em_portugues(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->actingAs(User::factory()->create())
            ->post(route('assets.store'), ['market' => 'crypto', 'symbol' => 'DOGEUSDT'])
            ->assertSessionHasErrors(['symbol' => 'Não foi possível consultar a Binance agora. Tente de novo em instantes.']);
    }

    public function test_remocao_so_sem_posicao_sinal_ou_carteira_acompanhando(): void
    {
        $user = User::factory()->create();
        $portfolio = Portfolio::factory()->create();

        $comPosicao = Asset::factory()->create();
        Position::create(['portfolio_id' => $portfolio->id, 'asset_id' => $comPosicao->id, 'qty' => 1, 'avg_price' => 10, 'opened_at' => now()]);
        $comSinal = Asset::factory()->create();
        Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $comSinal->id, 'stage' => SignalStage::Analyzed]);
        $acompanhado = Asset::factory()->create();
        $portfolio->assets()->attach($acompanhado);

        foreach ([$comPosicao, $comSinal, $acompanhado] as $asset) {
            $this->actingAs($user)->delete(route('assets.destroy', $asset))->assertSessionHasErrors('asset');
            $this->assertTrue($asset->fresh()->active);
        }

        $livre = Asset::factory()->create();
        $this->actingAs($user)->delete(route('assets.destroy', $livre))->assertSessionHasNoErrors();
        $this->assertModelMissing($livre);
        $this->assertSame(1, AuditLog::query()->where('action', 'asset.removed')->count());
    }

    public function test_ativo_com_historico_e_desativado_em_vez_de_apagado(): void
    {
        $portfolio = Portfolio::factory()->create();
        $asset = Asset::factory()->create();
        Signal::create(['portfolio_id' => $portfolio->id, 'asset_id' => $asset->id, 'stage' => SignalStage::Closed]);

        $this->actingAs(User::factory()->create())->delete(route('assets.destroy', $asset))->assertSessionHasNoErrors();

        $this->assertFalse($asset->fresh()->active);
    }

    public function test_referencia_do_relatorio_nao_sai(): void
    {
        $ibov = Asset::factory()->benchmark()->create();

        $this->actingAs(User::factory()->create())->delete(route('assets.destroy', $ibov))->assertSessionHasErrors('asset');
        $this->assertModelExists($ibov);
    }

    public function test_visitante_nao_acessa_a_tela_de_ativos(): void
    {
        $this->get(route('assets.index'))->assertRedirect(route('login'));
        $this->getJson(route('assets.search', ['market' => 'crypto', 'q' => 'btc']))->assertUnauthorized();
        $this->post(route('assets.store'), ['market' => 'crypto', 'symbol' => 'BTCUSDT'])->assertRedirect(route('login'));
    }

    public function test_tela_de_ativos_lista_os_cadastrados(): void
    {
        Asset::factory()->create(['symbol' => 'DOGEUSDT']);
        Asset::factory()->benchmark()->create();

        $this->actingAs(User::factory()->create())->get(route('assets.index'))
            ->assertInertia(fn ($page) => $page->component('Assets')->has('assets', 1)->where('assets.0.symbol', 'DOGEUSDT'));
    }

    public function test_dono_escolhe_os_ativos_da_carteira(): void
    {
        $portfolio = Portfolio::factory()->create();
        $a = Asset::factory()->create();
        $b = Asset::factory()->create();

        $this->actingAs($portfolio->user)->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertSame([$a->id], $portfolio->assets()->pluck('assets.id')->all());

        $this->actingAs($portfolio->user)->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $portfolio->assets()->count());

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn ($page) => $page->has('available_assets', 2)->where('tracked_asset_ids', []));
        $this->assertNotNull($b);
    }

    public function test_carteira_so_aceita_ativos_do_proprio_mercado(): void
    {
        $portfolio = Portfolio::factory()->create(['market' => Market::Crypto]);
        $b3 = Asset::factory()->b3()->create();
        $inativo = Asset::factory()->create(['active' => false]);

        $this->actingAs($portfolio->user)->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => [$b3->id]])->assertSessionHasErrors('asset_ids.0');
        $this->actingAs($portfolio->user)->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => [$inativo->id]])->assertSessionHasErrors('asset_ids.0');
    }

    public function test_outro_usuario_recebe_403_ao_mexer_nos_ativos_da_carteira(): void
    {
        $portfolio = Portfolio::factory()->create();
        $asset = Asset::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('portfolios.assets.update', $portfolio), ['asset_ids' => [$asset->id]])
            ->assertForbidden();

        $this->assertSame(0, $portfolio->assets()->count());
    }

    public function test_scanner_abre_sinal_so_nas_carteiras_que_acompanham_o_ativo(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute()->addSeconds(10));

        $doge = Asset::factory()->create(['symbol' => 'DOGEUSDT']);
        Candle::create(['asset_id' => $doge->id, 'timeframe' => '1m', 'open_at' => now()->startOfMinute(), 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);

        $todas = Portfolio::factory()->create();
        $escolheu = Portfolio::factory()->create();
        $escolheu->assets()->attach($doge);
        $outra = Portfolio::factory()->create();
        $outra->assets()->attach(Asset::factory()->create());

        app(SignalScanner::class)->scan($doge);

        $this->assertSame(1, $todas->signals()->count());
        $this->assertSame(1, $escolheu->signals()->count());
        $this->assertSame(0, $outra->signals()->count());
        Queue::assertPushed(AnalyzeSignalJob::class, 2);
    }

    public function test_coletor_reconecta_quando_um_ativo_novo_e_cadastrado(): void
    {
        config(['trading.binance.stream_reload_seconds' => 1]);
        Asset::factory()->create(['symbol' => 'BTCUSDT']);

        $this->app->instance(TradeStream::class, new class implements TradeStream
        {
            /** @var list<list<string>> */
            public static array $connections = [];

            public function listen(array $symbols, Closure $onTrade, Closure $onTick): void
            {
                self::$connections[] = $symbols;

                if (count(self::$connections) === 1) {
                    Asset::factory()->create(['symbol' => 'DOGEUSDT']);
                }

                for ($i = 0; $i < 4; $i++) {
                    sleep(1);

                    if (! $onTick()) {
                        return;
                    }
                }
            }
        });

        $this->artisan('market:stream-binance', ['--max-seconds' => 5])->assertSuccessful();

        $connections = $this->app->make(TradeStream::class)::$connections;
        $this->assertSame(['BTCUSDT'], $connections[0]);
        $this->assertEqualsCanonicalizing(['BTCUSDT', 'DOGEUSDT'], $connections[1]);
    }
}
