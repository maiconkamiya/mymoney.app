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
        // Fatos relevantes (comunicados oficiais das companhias). O nome da fonte é a etiqueta gravada
        // em cada notícia. Aponte FATOS_RELEVANTES_RSS_URL para o feed RSS da CVM ou da B3 e confirme
        // os termos de uso; sem a URL a fonte fica desligada.
        'fatos-relevantes' => ['driver' => 'rss', 'url' => env('FATOS_RELEVANTES_RSS_URL'), 'market' => 'b3'],
        'infomoney' => ['driver' => 'rss', 'url' => 'https://www.infomoney.com.br/feed/', 'market' => 'b3'],
    ],

    // Items at or above this relevance get the deeper analysis with the strong model.
    'deep_analysis_threshold' => 0.6,

    // Items at or above this relevance trigger a new agent round for the asset.
    'signal_threshold' => 0.6,

];
