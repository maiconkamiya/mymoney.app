<?php

use App\Trading\Brokers\BinanceBroker;
use App\Trading\Brokers\Mt5Broker;
use App\Trading\Brokers\PaperBroker;
use App\Trading\Gateways\Mt5HttpGateway;
use App\Trading\Gateways\RefusingOrderGateway;

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
            // Emolumentos (0,005%) + liquidação (0,0275%); corretagem is configured separately.
            'fee_rate' => (float) env('TRADING_B3_FEE_RATE', 0.000325),
            'brokerage_per_order' => (float) env('TRADING_B3_BROKERAGE', 0),
            'slippage_bps' => (float) env('TRADING_B3_SLIPPAGE_BPS', 5),
        ],
    ],

    /*
    | Cash equity session. Orders only fill in continuous trading
    | (opens_at to closes_at); the auctions are modelled as non-filling phases.
    */

    'b3_session' => [
        'timezone' => 'America/Sao_Paulo',
        'opening_auction_at' => '09:45',
        'opens_at' => '10:00',
        'closes_at' => '16:55',
        'closing_call_until' => '17:00',
    ],

    /*
    | Extra closings beyond the national calendar (Y-m-d), for example a
    | day the exchange announces it will not trade.
    */

    'b3_extra_holidays' => array_filter(explode(',', (string) env('TRADING_B3_EXTRA_HOLIDAYS', ''))),

    /*
    | B3 market data provider (brapi.dev). Free plans only serve daily candles
    | for most tickers; set the interval your plan allows.
    */

    'b3_data' => [
        'token' => env('BRAPI_TOKEN'),
        'interval' => env('B3_CANDLE_INTERVAL', '1m'),
        'range' => env('B3_CANDLE_RANGE', '1d'),
    ],

    /*
    | Live order gateway for B3. Refuses everything until MT5_GATEWAY_URL points
    | at the bridge next to the terminal.
    */

    'b3_gateway' => env('MT5_GATEWAY_URL') ? Mt5HttpGateway::class : RefusingOrderGateway::class,

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
