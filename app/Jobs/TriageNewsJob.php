<?php

namespace App\Jobs;

use App\Agents\SignalScanner;
use App\Models\NewsItem;
use App\News\NewsTriage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TriageNewsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $newsItemId) {}

    public function handle(NewsTriage $triage, SignalScanner $scanner): void
    {
        $news = NewsItem::find($this->newsItemId);

        if ($news === null || $news->triaged_at !== null) {
            return;
        }

        foreach ($triage->triage($news) as $asset) {
            $relevance = (float) $news->assets()->whereKey($asset->id)->first()?->pivot->relevance;

            if ($relevance >= config('news.signal_threshold')) {
                $scanner->scan($asset);
            }
        }
    }
}
