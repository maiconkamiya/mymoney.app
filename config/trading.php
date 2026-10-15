<?php

use App\Trading\Brokers\BinanceBroker;
use App\Trading\Brokers\Mt5Broker;
use App\Trading\Brokers\PaperBroker;

return [

    /*
    |--------------------------------------------------------------------------
    | Brokers
    |--------------------------------------------------------------------------
    |
    | Every portfolio names one of these drivers. Paper and live portfolios go
    | through the same pipeline; only the driver at the end changes.
    |
    */

    'brokers' => [
        'paper' => PaperBroker::class,
        'binance' => BinanceBroker::class,
        'mt5' => Mt5Broker::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Simulation costs
    |--------------------------------------------------------------------------
    |
    | Rates are fractions of the traded value. Check them against your broker
    | and exchange before trusting a simulated result.
    |
    */

    'costs' => [
        'crypto' => [
            'fee_rate' => (float) env('TRADING_CRYPTO_FEE_RATE', 0.001),
            'slippage_bps' => (float) env('TRADING_CRYPTO_SLIPPAGE_BPS', 5),
        ],
        'b3' => [
            // Emolumentos + liquidação; corretagem is configured separately.
            'fee_rate' => (float) env('TRADING_B3_FEE_RATE', 0.0003),
            'brokerage_per_order' => (float) env('TRADING_B3_BROKERAGE', 0),
            'slippage_bps' => (float) env('TRADING_B3_SLIPPAGE_BPS', 5),
        ],
    ],

    'b3_session' => [
        'timezone' => 'America/Sao_Paulo',
        'opens_at' => '10:00',
        'closes_at' => '16:55',
    ],

    /*
    |--------------------------------------------------------------------------
    | Market data freshness
    |--------------------------------------------------------------------------
    |
    | No new orders are sent when the last price is older than this.
    |
    */

    'max_price_age_seconds' => [
        'crypto' => 30,
        'b3' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-money execution (phase 5)
    |--------------------------------------------------------------------------
    |
    | Off by default. Even when enabled, every real order needs a person's
    | explicit approval, stays under these absolute caps (in the portfolio's
    | currency) and requires a verified credential without withdrawal rights.
    |
    */

    'live' => [
        'enabled' => (bool) env('TRADING_LIVE_ENABLED', false),
        'max_order_value' => (float) env('TRADING_LIVE_MAX_ORDER_VALUE', 20),
        'max_daily_value' => (float) env('TRADING_LIVE_MAX_DAILY_VALUE', 100),
    ],

    'binance' => [
        'base_url' => env('BINANCE_BASE_URL', 'https://api.binance.com'),
        'recv_window' => (int) env('BINANCE_RECV_WINDOW', 5000),
    ],

    // Bridge in front of the MT5 terminal (MT5 has no official REST API).
    'mt5' => [
        'gateway_url' => env('MT5_GATEWAY_URL'),
        'gateway_token' => env('MT5_GATEWAY_TOKEN'),
    ],

];
