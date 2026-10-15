<?php

namespace Tests\Feature\News;

use App\Agents\Llm\FakeLlmClient;
use App\Agents\Llm\LlmClient;
use App\Models\Asset;
use App\Models\NewsItem;
use App\News\NewsIngestor;
use App\News\NewsTriage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
