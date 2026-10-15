<?php

namespace App\Jobs;

use App\News\NewsIngestor;
use App\News\RssNewsSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchNewsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $source) {}

    public function handle(NewsIngestor $ingestor): void
    {
        $config = config('news.sources.'.$this->source);

        $feed = match ($config['driver']) {
            'rss' => new RssNewsSource($config['url']),
        };

        foreach ($ingestor->ingest($this->source, $feed->fetch()) as $news) {
            TriageNewsJob::dispatch($news->id);
        }
    }
}
