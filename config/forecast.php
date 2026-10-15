<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Forecast service
    |--------------------------------------------------------------------------
    |
    | Python service in services/forecast that estimates the probability of an
    | up or down move for the quant agent. Off by default: with it off, or when
    | the service is unreachable, the scenario carries no forecast and the quant
    | agent votes hold.
    |
    */

    'enabled' => (bool) env('FORECAST_ENABLED', false),

    'url' => env('FORECAST_URL', 'http://127.0.0.1:8001'),

    'token' => env('FORECAST_TOKEN'),

    'timeout_seconds' => (int) env('FORECAST_TIMEOUT', 5),

    /*
    | Training: candles ahead to predict, history window and the Python used by
    | `php artisan forecast:train`.
    */

    'horizon' => (int) env('FORECAST_HORIZON', 15),

    'training_days' => (int) env('FORECAST_TRAINING_DAYS', 30),

    'python' => env('FORECAST_PYTHON', 'python3'),

];
