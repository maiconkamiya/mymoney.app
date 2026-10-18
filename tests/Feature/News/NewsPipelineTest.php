<?php

namespace Tests\Feature\News;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Jobs\FetchNewsJob;
use App\Jobs\TriageNewsJob;
use App\Models\Asset;
use App\Models\NewsItem;
use App\News\NewsIngestor;
use App\News\NewsTriage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewsPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingestor_skips_urls_already_seen()
    {
        $item = ['url' => 'https://example.com/a/', 'title' => 'BTC sobe', 'body' => null, 'published_at' => 'Thu, 15 Oct 2026 12:00:00 GMT'];

        $first = app(NewsIngestor::class)->ingest('rss', [$item]);
        $second = app(NewsIngestor::class)->ingest('rss', [array_merge($item, ['url' => 'https://EXAMPLE.com/a'])]);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertSame(1, NewsItem::count());
    }

    public function test_relevant_facts_feed_is_stored_under_its_own_source_label()
    {
        config(['news.sources.fatos-relevantes.url' => 'https://feeds.test/fatos.xml']);
        Http::fake(['feeds.test/*' => Http::response('<?xml version="1.0"?><rss><channel><item><title>Petrobras: Fato Relevante</title><link>https://feeds.test/fr/1</link><description><![CDATA[<p>Novo dividendo.</p>]]></description><pubDate>Thu, 15 Oct 2026 12:00:00 GMT</pubDate></item></channel></rss>')]);
        Queue::fake([TriageNewsJob::class]);

        (new FetchNewsJob('fatos-relevantes'))->handle(app(NewsIngestor::class));

        $item = NewsItem::firstOrFail();
        $this->assertSame('fatos-relevantes', $item->source);
        $this->assertSame('Novo dividendo.', $item->body);
        Queue::assertPushed(TriageNewsJob::class);
    }

    public function test_relevant_facts_source_is_off_without_a_feed_url()
    {
        config(['news.sources.fatos-relevantes.url' => null]);
        Http::fake();

        (new FetchNewsJob('fatos-relevantes'))->handle(app(NewsIngestor::class));

        Http::assertNothingSent();
        $this->assertSame(0, NewsItem::count());
    }

    public function test_b3_sources_are_skipped_when_switched_off_in_the_settings()
    {
        config(['news.sources.fatos-relevantes.url' => 'https://feeds.test/fatos.xml', 'news.b3_sources' => false]);
        Http::fake();

        (new FetchNewsJob('fatos-relevantes'))->handle(app(NewsIngestor::class));

        Http::assertNothingSent();
        $this->assertSame(0, NewsItem::count());
    }

    public function test_triage_attaches_known_assets_and_runs_deep_analysis_when_relevant()
    {
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $news = NewsItem::create([
            'source' => 'rss', 'url' => 'https://example.com/b', 'url_hash' => NewsItem::hashUrl('https://example.com/b'),
            'title' => 'ETF de bitcoin aprovado', 'fetched_at' => now(),
        ]);

        $fake = new FakeLlmClient(fn (string $system) => str_contains($system, 'analista sênior')
            ? ['impact' => 'positive', 'horizon' => 'days', 'already_priced' => false, 'summary' => 'Fluxo novo.']
            : ['category' => 'regulação', 'assets' => [
                ['symbol' => 'btcusdt', 'sentiment' => 0.9, 'relevance' => 0.8, 'is_new_fact' => true],
                ['symbol' => 'DOGEUSDT', 'sentiment' => 0.1, 'relevance' => 0.2, 'is_new_fact' => true],
            ]]);
        $this->app->instance(LlmClient::class, $fake);

        $attached = app(NewsTriage::class)->triage($news);

        $this->assertSame([$btc->id], array_map(fn (Asset $a) => $a->id, $attached));
        $pivot = $news->assets()->first()->pivot;
        $this->assertEqualsWithDelta(0.8, (float) $pivot->relevance, 1e-9);
        $this->assertSame('positive', json_decode($pivot->analysis, true)['impact']);
        $this->assertNotNull($news->fresh()->triaged_at);
        $this->assertCount(2, $fake->calls);
    }
}
