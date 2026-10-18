<?php

namespace Tests\Feature\Ativos;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Insights\FearGreedIndex;
use App\Insights\PriceHistory;
use App\Models\Asset;
use App\Models\AssetInsight;
use App\Models\NewsItem;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaginaDoAtivoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['insights.ai.enabled' => false]);
    }

    /**
     * Velas de `$count` com o preço indo de `$from` a `$to` em linha reta.
     *
     * @return list<list<mixed>>
     */
    private function klines(int $count, float $from, float $to): array
    {
        $rows = [];
        $start = now()->subDays(400)->getTimestampMs();

        for ($i = 0; $i < $count; $i++) {
            $open = $from + ($to - $from) * $i / $count;
            $close = $from + ($to - $from) * ($i + 1) / $count;
            $rows[] = [$start + $i * 60_000, (string) $open, (string) (max($open, $close) + 1), (string) (min($open, $close) - 1), (string) $close, '10'];
        }

        return $rows;
    }

    private function fakeSources(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'alternative.me')) {
                return Http::response(['data' => array_map(fn (int $i) => [
                    'value' => (string) (20 + $i),
                    'value_classification' => $i === 0 ? 'Extreme Fear' : 'Fear',
                    'timestamp' => (string) now()->subDays($i)->startOfDay()->getTimestamp(),
                ], range(0, 30))]);
            }

            return match ($request['interval']) {
                '15m' => Http::response($this->klines(96, 100, 95)),
                '1h' => Http::response($this->klines(168, 110, 95)),
                '4h' => Http::response($this->klines(180, 80, 95)),
                '1d' => Http::response($this->klines(365, 50, 95)),
                default => Http::response([], 400),
            };
        });
    }

    public function test_historico_calcula_as_cinco_variacoes_com_quatro_chamadas(): void
    {
        $this->fakeSources();

        $history = app(PriceHistory::class)->for('BTCUSDT');

        $this->assertNotNull($history);
        $this->assertSame(['24h', '7d', '1m', '3m', '1y'], array_keys($history));
        $this->assertSame(-5.0, $history['24h']['change_pct']);
        $this->assertSame(90.0, $history['1y']['change_pct']);
        $this->assertCount(90, $history['3m']['points']);
        $this->assertSame(95.0, $history['3m']['close']);
        $this->assertCount(96, $history['24h']['points']);
        Http::assertSentCount(4);

        // Guardado: a segunda abertura não chama a Binance de novo.
        app(PriceHistory::class)->for('BTCUSDT');
        Http::assertSentCount(4);
    }

    public function test_historico_devolve_null_quando_a_binance_falha(): void
    {
        Http::fake(['*' => Http::response('', 451)]);

        $this->assertNull(app(PriceHistory::class)->for('BTCUSDT'));
    }

    public function test_sentimento_traduz_o_indice_e_compara_com_dias_anteriores(): void
    {
        $this->fakeSources();

        $index = app(FearGreedIndex::class)->current();

        $this->assertNotNull($index);
        $this->assertSame(20, $index['value']);
        $this->assertSame('Medo extremo', $index['label']);
        $this->assertSame(21, $index['yesterday']);
        $this->assertSame(27, $index['last_week']);
        $this->assertSame(50, $index['last_month']);
        $this->assertSame('Ganância', FearGreedIndex::label('', 60));
    }

    public function test_pagina_do_ativo_abre_e_carrega_historico_e_sentimento_depois(): void
    {
        $this->fakeSources();
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT', 'name' => 'Bitcoin']);

        $this->actingAs(User::factory()->create())
            ->get(route('assets.show', $asset))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('assets/Show')
                ->where('asset.base', 'BTC')
                ->where('ai_enabled', false)
                ->where('insight', null)
                ->missing('history')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('history.24h.change_pct', -5)
                    ->where('sentiment.label', 'Medo extremo')));
    }

    public function test_pagina_so_existe_para_cripto(): void
    {
        $asset = Asset::factory()->b3()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('assets.show', $asset))
            ->assertNotFound();
    }

    public function test_analise_desligada_nao_chama_a_ia(): void
    {
        $this->fakeSources();
        $fake = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $fake);
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);

        $this->actingAs(User::factory()->create())
            ->post(route('assets.insight', $asset))
            ->assertRedirect();

        $this->artisan('insights:write')->assertSuccessful();

        $this->assertSame([], $fake->calls);
        $this->assertSame(0, AssetInsight::query()->count());
    }

    public function test_analise_ligada_escreve_com_direcao_do_codigo_e_manchetes(): void
    {
        config(['insights.ai.enabled' => true]);
        $this->fakeSources();
        $fake = new FakeLlmClient(fn () => [
            'summary' => 'O BTC cai 5% em 24 h, num mercado com medo extremo.',
            'points' => ['Queda contínua ao longo do dia.', 'Sentimento em 20.', '', 'Manchete sobre ETF.', 'Quinto ponto descartado.'],
        ]);
        $this->app->instance(LlmClient::class, $fake);
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        NewsItem::query()->create([
            'source' => 'coindesk', 'url' => 'https://example.com/a', 'url_hash' => sha1('a'),
            'title' => 'ETFs de bitcoin têm saída recorde', 'fetched_at' => now()->subHours(2),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('assets.insight', $asset))
            ->assertRedirect();

        $insight = AssetInsight::query()->sole();
        $this->assertSame('down', $insight->direction);
        $this->assertSame(-5.0, $insight->change_24h_pct);
        $this->assertSame(1, $insight->news_count);
        $this->assertCount(4, $insight->points);
        $this->assertCount(1, $fake->calls);
        $this->assertStringContainsString('em queda', $fake->calls[0]['system']);
        $this->assertStringContainsString('ETFs de bitcoin', $fake->calls[0]['prompt']);

        // Um segundo pedido logo em seguida reaproveita a análise.
        $this->actingAs(User::factory()->create())->post(route('assets.insight', $asset));
        $this->assertCount(1, $fake->calls);
    }

    public function test_rotina_diaria_so_analisa_ativos_acompanhados(): void
    {
        config(['insights.ai.enabled' => true]);
        $this->fakeSources();
        $fake = new FakeLlmClient(fn () => ['summary' => 'Resumo.', 'points' => []]);
        $this->app->instance(LlmClient::class, $fake);

        $tracked = Asset::factory()->create(['symbol' => 'SOLUSDT']);
        $other = Asset::factory()->create(['symbol' => 'ETHUSDT']);
        Portfolio::factory()->create()->assets()->sync([$tracked->id]);

        $this->artisan('insights:write')->assertSuccessful();

        $this->assertSame([$tracked->id], AssetInsight::query()->pluck('asset_id')->all());
        $this->assertFalse(AssetInsight::query()->where('asset_id', $other->id)->exists());
    }

    public function test_chave_da_analise_aparece_em_custos_e_servicos(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('system-settings.update'), ['insights__ai__enabled' => true])
            ->assertRedirect();

        $this->assertTrue((bool) config('insights.ai.enabled'));
    }
}
