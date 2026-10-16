<?php

namespace Tests\Feature\News;

use App\Models\Asset;
use App\Models\Candle;
use App\Models\NewsItem;
use App\News\Embeddings\Embedder;
use App\News\Embeddings\EmbeddingException;
use App\News\Embeddings\FakeEmbedder;
use App\News\Embeddings\HttpEmbedder;
use App\News\Embeddings\SimilarNewsFinder;
use App\News\NewsHistory;
use App\News\NewsIngestor;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemanticDedupTest extends TestCase
{
    use RefreshDatabase;

    private const ETF_A = ['url' => 'https://a.example.com/etf', 'title' => 'Bitcoin ETF aprovado pela SEC', 'body' => 'A SEC aprovou o primeiro ETF de bitcoin à vista nos Estados Unidos.', 'published_at' => null];

    private const ETF_B = ['url' => 'https://b.example.com/sec-aprova', 'title' => 'SEC aprova ETF de bitcoin à vista', 'body' => 'O primeiro ETF de bitcoin à vista foi aprovado pela SEC nos Estados Unidos.', 'published_at' => null];

    private const PETRO = ['url' => 'https://a.example.com/petro', 'title' => 'Petrobras anuncia dividendos extraordinários', 'body' => 'A Petrobras aprovou dividendos extraordinários para acionistas.', 'published_at' => null];

    protected function setUp(): void
    {
        parent::setUp();

        config(['news.dedup_threshold' => 0.85, 'news.embeddings.dimensions' => 1024]);
        $this->app->instance(Embedder::class, new FakeEmbedder(1024));
    }

    public function test_same_fact_from_another_source_is_stored_as_duplicate_and_skips_triage()
    {
        $ingestor = app(NewsIngestor::class);

        $first = $ingestor->ingest('coindesk', [self::ETF_A]);
        $second = $ingestor->ingest('infomoney', [self::ETF_B]);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertSame(2, NewsItem::count());
        $this->assertSame($first[0]->id, NewsItem::where('source', 'infomoney')->first()->duplicate_of_id);
    }

    public function test_unrelated_news_is_not_a_duplicate()
    {
        $ingestor = app(NewsIngestor::class);

        $ingestor->ingest('coindesk', [self::ETF_A]);
        $created = $ingestor->ingest('infomoney', [self::PETRO]);

        $this->assertCount(1, $created);
        $this->assertNull($created[0]->duplicate_of_id);
        $this->assertNotNull($created[0]->embedding);
    }

    public function test_news_outside_the_dedup_window_is_not_a_duplicate()
    {
        config(['news.dedup_window_hours' => 24]);
        $ingestor = app(NewsIngestor::class);

        $this->travelTo(now()->subDays(3));
        $ingestor->ingest('coindesk', [self::ETF_A]);
        $this->travelBack();

        $this->assertCount(1, $ingestor->ingest('infomoney', [self::ETF_B]));
    }

    public function test_embedding_failure_does_not_block_ingestion()
    {
        $this->app->instance(Embedder::class, new class implements Embedder
        {
            public function embed(string $text): array
            {
                throw new EmbeddingException('fora do ar');
            }
        });

        $created = app(NewsIngestor::class)->ingest('coindesk', [self::ETF_A]);

        $this->assertCount(1, $created);
        $this->assertNull($created[0]->embedding);
    }

    public function test_finder_never_returns_news_fetched_after_the_reference_time()
    {
        $ingestor = app(NewsIngestor::class);
        $ingestor->ingest('coindesk', [self::ETF_A]);
        $vector = app(Embedder::class)->embed(NewsIngestor::textFor(self::ETF_B['title'], self::ETF_B['body']));

        $finder = app(SimilarNewsFinder::class);

        $this->assertFalse($finder->usesPgvector());
        $this->assertCount(1, $finder->nearest($vector, 0.5, 5));
        $this->assertCount(0, $finder->nearest($vector, 0.5, 5, ['asOf' => now()->subHour()]));
    }

    public function test_history_reports_the_price_move_after_similar_past_news()
    {
        $asset = Asset::factory()->create();
        $ingestor = app(NewsIngestor::class);

        $this->travelTo(now()->subDays(3));
        $past = $ingestor->ingest('coindesk', [self::ETF_A])[0];
        $past->assets()->attach($asset->id, ['sentiment' => 0.8, 'relevance' => 0.9, 'is_new_fact' => true]);
        $this->candle($asset, now(), 100);
        $this->candle($asset, now()->addHours(24), 110);
        $this->travelBack();

        config(['news.dedup_threshold' => 1.1]);
        $current = $ingestor->ingest('infomoney', [self::ETF_B])[0];
        $current->assets()->attach($asset->id, ['sentiment' => 0.8, 'relevance' => 0.9, 'is_new_fact' => true]);

        $history = app(NewsHistory::class)->forAsset($asset, [$current]);

        $this->assertCount(1, $history);
        $this->assertSame("news:{$past->id}", $history[0]['id']);
        $this->assertSame("news:{$current->id}", $history[0]['similar_to']);
        $this->assertSame(10.0, $history[0]['price_change_pct']);
    }

    public function test_history_leaves_the_price_move_empty_while_the_horizon_is_in_the_future()
    {
        $asset = Asset::factory()->create();
        $ingestor = app(NewsIngestor::class);

        $this->travelTo(now()->subHours(2));
        $past = $ingestor->ingest('coindesk', [self::ETF_A])[0];
        $past->assets()->attach($asset->id, ['sentiment' => 0.8, 'relevance' => 0.9, 'is_new_fact' => true]);
        $this->candle($asset, now(), 100);
        $this->travelBack();

        config(['news.dedup_threshold' => 1.1]);
        $current = $ingestor->ingest('infomoney', [self::ETF_B])[0];

        $history = app(NewsHistory::class)->forAsset($asset, [$current]);

        $this->assertCount(1, $history);
        $this->assertNull($history[0]['price_change_pct']);
    }

    public function test_http_embedder_parses_and_normalises_the_provider_response()
    {
        Http::fake(['*' => Http::response(['data' => [['embedding' => [3.0, 4.0]]]])]);

        $vector = (new HttpEmbedder('https://embeddings.test/v1', 'chave', 'modelo', 2))->embed('texto');

        $this->assertEqualsWithDelta([0.6, 0.8], $vector, 1e-9);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer chave') && $request['model'] === 'modelo');
    }

    public function test_http_embedder_rejects_a_vector_with_the_wrong_size()
    {
        Http::fake(['*' => Http::response(['data' => [['embedding' => [1.0, 2.0, 3.0]]]])]);

        $this->expectException(EmbeddingException::class);

        (new HttpEmbedder('https://embeddings.test/v1', 'chave', 'modelo', 2))->embed('texto');
    }

    private function candle(Asset $asset, DateTimeInterface $at, float $close): void
    {
        Candle::create([
            'asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => $at,
            'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => 1,
        ]);
    }
}
