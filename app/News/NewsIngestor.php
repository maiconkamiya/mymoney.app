<?php

namespace App\News;

use App\Models\NewsItem;
use Illuminate\Support\Carbon;
use Throwable;

class NewsIngestor
{
    /**
     * Store items not seen before (by canonical URL) and return the new ones.
     *
     * @param  iterable<array{url: string, title: string, body: string|null, published_at: string|null}>  $items
     * @return list<NewsItem>
     */
    public function ingest(string $source, iterable $items): array
    {
        $created = [];

        foreach ($items as $item) {
            if ($item['url'] === '' || $item['title'] === '') {
                continue;
            }

            $news = NewsItem::firstOrCreate(
                ['url_hash' => NewsItem::hashUrl($item['url'])],
                [
                    'source' => $source,
                    'url' => $item['url'],
                    'title' => mb_substr($item['title'], 0, 255),
                    'body' => $item['body'],
                    'published_at' => $this->parseDate($item['published_at']),
                    'fetched_at' => now(),
                ],
            );

            if ($news->wasRecentlyCreated) {
                $created[] = $news;
            }
        }

        return $created;
    }

    private function parseDate(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
