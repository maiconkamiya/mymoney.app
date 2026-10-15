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

];
