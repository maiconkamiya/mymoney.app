<?php

namespace App\News;

interface NewsSource
{
    /**
     * @return iterable<array{url: string, title: string, body: string|null, published_at: string|null}>
     */
    public function fetch(): iterable;
}
