<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Binance stream
    |--------------------------------------------------------------------------
    |
    | Combined WebSocket stream of 1-minute klines for the active crypto assets.
    | `backfill` is how many candles are loaded over REST when the stream starts
    | (and again after a reconnect) so the chart has no holes.
    |
    */

    'binance' => [
        'ws_url' => env('BINANCE_WS_URL', 'wss://stream.binance.com:9443/stream'),
        'backfill' => 120,
        'reconnect_max_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Collector status
    |--------------------------------------------------------------------------
    |
    | The collector counts as stalled when the last message is older than this.
    |
    */

    'collector' => [
        'stale_after_seconds' => 15,
        'candles_on_load' => 120,
    ],

];
