<?php

namespace App\News;

use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

class RssNewsSource implements NewsSource
{
    public function __construct(private readonly string $url) {}

    public function fetch(): iterable
    {
        $body = Http::timeout(20)->get($this->url)->throw()->body();
        $xml = new SimpleXMLElement($body, LIBXML_NOCDATA);

        foreach ($xml->channel->item ?? [] as $item) {
            yield [
                'url' => trim((string) $item->link),
                'title' => trim((string) $item->title),
                'body' => trim(strip_tags((string) $item->description)) ?: null,
                'published_at' => ((string) $item->pubDate) ?: null,
            ];
        }
    }
}
