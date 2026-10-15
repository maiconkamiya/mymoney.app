<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | RSS feeds polled by the scheduler. Check each site's terms of use before
    | turning a feed on.
    |
    */

    'sources' => [
        'coindesk' => ['driver' => 'rss', 'url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/', 'market' => 'crypto'],
        'infomoney' => ['driver' => 'rss', 'url' => 'https://www.infomoney.com.br/feed/', 'market' => 'b3'],
    ],

    // Items at or above this relevance get the deeper analysis with the strong model.
    'deep_analysis_threshold' => 0.6,

    // Items at or above this relevance trigger a new agent round for the asset.
    'signal_threshold' => 0.6,

    /*
    |--------------------------------------------------------------------------
    | Embeddings and semantic deduplication
    |--------------------------------------------------------------------------
    |
    | "fake" hashes words into a vector (no key, deterministic, used in tests).
    | "http" calls any endpoint in the OpenAI embeddings format, such as Voyage
    | AI (https://api.voyageai.com/v1/embeddings). "dimensions" must match the
    | model and, with pgvector, the column created by the migration.
    |
    */

    'embeddings' => [
        'driver' => env('NEWS_EMBEDDINGS_DRIVER', 'fake'),
        'dimensions' => (int) env('NEWS_EMBEDDINGS_DIMENSIONS', 1024),
        'url' => env('NEWS_EMBEDDINGS_URL', 'https://api.voyageai.com/v1/embeddings'),
        'key' => env('NEWS_EMBEDDINGS_KEY'),
        'model' => env('NEWS_EMBEDDINGS_MODEL', 'voyage-3'),
        'timeout_seconds' => (int) env('NEWS_EMBEDDINGS_TIMEOUT', 30),
    ],

    // Cosine similarity at or above this marks an item as a duplicate of an earlier one.
    'dedup_threshold' => (float) env('NEWS_DEDUP_THRESHOLD', 0.92),

    // Only compare against items fetched within this many hours.
    'dedup_window_hours' => (int) env('NEWS_DEDUP_WINDOW_HOURS', 72),

    // "auto" uses pgvector on Postgres and an in-memory scan elsewhere; force with "memory" or "pgvector".
    'vector_store' => env('NEWS_VECTOR_STORE', 'auto'),

    // Most recent items the in-memory store scans per search.
    'memory_scan_limit' => 2000,

    /*
    | Historical context for the news agent: for the latest news about an
    | asset, similar past news and how the price moved after them.
    */

    'history' => [
        'min_similarity' => (float) env('NEWS_HISTORY_MIN_SIMILARITY', 0.6),
        'per_news' => 3,
        'max_current_news' => 3,
        'horizon_hours' => 24,
    ],

];
