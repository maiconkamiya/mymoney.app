<?php

namespace App\Jobs;

use App\News\NewsIngestor;
use App\News\RssNewsSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class FetchNewsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $source) {}

    public function handle(NewsIngestor $ingestor): void
    {
        $config = config('news.sources.'.$this->source);

        if (blank($config['url'] ?? null)) {
            return; // source configured but switched off (no feed URL)
        }

        $feed = match ($config['driver']) {
            'rss' => new RssNewsSource($config['url']),
            default => throw new InvalidArgumentException("Fonte de notícias [{$this->source}] com driver desconhecido."),
        };

        foreach ($ingestor->ingest($this->source, $feed->fetch()) as $news) {
            TriageNewsJob::dispatch($news->id);
        }
    }
}
