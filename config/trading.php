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
    | Quarta-feira de Cinzas: the session opens at 13h (auction from 12:45) and
    | closes as usual. Overrides the matching keys of `b3_session` on that day.
    */

    'b3_ash_wednesday_session' => [
        'opening_auction_at' => '12:45',
        'opens_at' => '13:00',
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
        // brapi.dev serves the Ibovespa index under its Yahoo-style ticker.
        'benchmark_tickers' => ['IBOV' => '^BVSP'],
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
    | Signal expiry
    |--------------------------------------------------------------------------
    |
    | An approved signal that nobody confirmed within this many minutes is
    | discarded: its stop, target and price no longer describe the market.
    |
    */

    'consensus' => [
        // Agents that abstain (no data) do not count; below this many valid votes the signal is rejected.
        'min_voters' => max(1, (int) env('TRADING_CONSENSUS_MIN_VOTERS', 3)),
    ],

    /*
    | Fixed rule against buying a rise too late (signals of the agents only;
    | micro operations do not use it): the room up to the probable ceiling must
    | pay this many times the risk to the stop, and the price may sit at most
    | this many ATR above the 21-period EMA.
    */

    'late_entry' => [
        'enabled' => (bool) env('TRADING_LATE_ENTRY_ENABLED', true),
        'min_reward_risk' => (float) env('TRADING_LATE_ENTRY_MIN_REWARD_RISK', 1.5),
        'max_ema21_atr' => (float) env('TRADING_LATE_ENTRY_MAX_EMA21_ATR', 3.0),
    ],

    'signal_ttl_minutes' => (int) env('TRADING_SIGNAL_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Scanner and price-move trigger
    |--------------------------------------------------------------------------
    |
    | The scheduled scanner opens signals every `scan_interval_minutes`. The
    | trigger does not wait for it: when the last close moves by at least
    | `pct` percent against the close `window_minutes` ago, the agents are
    | called right away (at most once per `cooldown_minutes` per asset).
    | The usual guards still apply: running portfolio, open market, fresh
    | quote, one analysis per asset at a time, and the risk guard.
    |
    */

    'scan_interval_minutes' => max(1, min(60, (int) env('TRADING_SCAN_INTERVAL_MINUTES', 15))),

    // B3 quotes and fundamentals in the scheduler (off while only crypto is traded).
    'b3_sync' => (bool) env('TRADING_B3_SYNC', true),

    'move_trigger' => [
        'enabled' => (bool) env('TRADING_MOVE_TRIGGER_ENABLED', true),
        'pct' => (float) env('TRADING_MOVE_TRIGGER_PCT', 1.0),
        'window_minutes' => max(1, (int) env('TRADING_MOVE_TRIGGER_WINDOW_MINUTES', 5)),
        'cooldown_minutes' => max(0, (int) env('TRADING_MOVE_TRIGGER_COOLDOWN_MINUTES', 15)),
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
        // Consultas seguidas da mesma ordem sem resposta da corretora (tempo esgotado, sem conexão, 5xx)
        // antes da conciliação ligar a Parada geral. Com a conciliação a cada 5 min, 6 = 30 min.
        'reconcile_max_failures' => (int) env('TRADING_LIVE_RECONCILE_MAX_FAILURES', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Micro operações (cripto)
    |--------------------------------------------------------------------------
    |
    | Modo por carteira, desligado por padrão. Sem modelo de IA: tendência,
    | suporte, resistência e volatilidade saem dos candles de 1 minuto. A
    | margem bruta do alvo é a líquida do dono mais o custo da ida e volta
    | (taxas + deslize de `costs.crypto`). A guarda de risco e os limites
    | reais valem em toda ordem.
    |
    */

    'micro' => [
        // Carteira real: ordem mínima da Binance no spot (5 USDT) ou o `min_notional` do par, se maior.
        // Carteira simulada aceita valores menores.
        'min_order_value' => (float) env('TRADING_MICRO_MIN_ORDER_VALUE', 5),
        'min_order_value_paper' => (float) env('TRADING_MICRO_MIN_ORDER_VALUE_PAPER', 1),
        'lookback_minutes' => 120,
        'atr_window' => 30,
        'ranking_size' => (int) env('TRADING_MICRO_RANKING_SIZE', 5),
        'max_open_cycles' => (int) env('TRADING_MICRO_MAX_OPEN', 3),
        'max_hold_minutes' => (int) env('TRADING_MICRO_MAX_HOLD_MINUTES', 60),
        'cooldown_minutes' => (int) env('TRADING_MICRO_COOLDOWN_MINUTES', 10),
        'support_zone' => 0.6,
        // O alvo bruto só vale se couber em `reach_factor` vezes o movimento típico do par no tempo limite
        // (faixa média por minuto vezes a raiz do tempo limite em minutos).
        'reach_factor' => 2.0,
        'stop_ratio' => 1.0,
        // Carteira real: a compra cobre o mínimo do par mesmo vendida no stop com este deslize a mais (#175).
        'stop_slippage_pct' => (float) env('TRADING_MICRO_STOP_SLIPPAGE_PCT', 0.5),
        'max_candle_age_seconds' => 150,

        // Preço sugerido de compra (#187): `pullback_atr` vezes a faixa média por minuto abaixo do menor
        // entre o último fechamento e a cotação de agora, sem descer do suporte, no tick do par. A compra
        // limitada nesse preço espera de `min_seconds` a `max_seconds` (o dono escolhe no card).
        'limit' => [
            'pullback_atr' => (float) env('TRADING_MICRO_LIMIT_PULLBACK_ATR', 0.5),
            'min_seconds' => 10,
            'max_seconds' => 600,
        ],

        // Motor em tempo real (`micro:watch`, serviço mymoney-micro): confere stop, alvo e tempo dos
        // ciclos abertos a cada `exit_seconds` e roda o motor inteiro (entradas) a cada `entry_seconds`.
        // Um ciclo que disparou e não saiu (venda recusada, abaixo do mínimo) espera `retry_seconds`.
        // Com o processo vivo há menos de `alive_seconds`, o `micro:run` por minuto fica de reserva.
        'realtime' => [
            'exit_seconds' => max(1, (int) env('TRADING_MICRO_REALTIME_EXIT_SECONDS', 1)),
            'entry_seconds' => max(1, (int) env('TRADING_MICRO_REALTIME_ENTRY_SECONDS', 3)),
            'retry_seconds' => 5,
            'notify_seconds' => 30,
            'alive_seconds' => 30,
        ],

        // Regra "Tendência com recuo": EMA21 de 1 h subindo, EMA9 acima da EMA21 em 15 min, entrada
        // até `pullback_atr` ATR de 15 min da EMA21 de 15 min com a vela de 5 min virando para cima.
        // Stop a `stop_atr` ATR de 15 min; alvo `reward_risk` vezes o risco e no mínimo
        // `min_target_costs` vezes o custo da ida e volta.
        'trend' => [
            'lookback_minutes' => 1500,
            'max_hold_minutes' => 240,
            'pullback_atr' => 0.5,
            'stop_atr' => 1.5,
            'reward_risk' => 1.5,
            'min_target_costs' => 4.0,
        ],

        // Regra "Atual + alta em 24 h e 4 h": a leitura de 1 minuto da regra atual e, além dela, o preço
        // acima do fechamento de `day_minutes` e de `recent_minutes` atrás (estudo de 2026-10-18, #172).
        'uptrend' => [
            'day_minutes' => 1440,
            'recent_minutes' => 240,
        ],

        // Regra "Atual + alta + RSI e volume" (#204): a regra "Atual + alta em 24 h e 4 h" e, além dela,
        // o RSI de `rsi_period` velas de 1 min abaixo de `rsi_max` (não compra esticado) e o volume médio
        // das últimas `volume_recent_minutes` velas acima de `volume_ratio` vezes o das
        // `volume_base_minutes` antes delas.
        'filtered' => [
            'rsi_period' => 14,
            'rsi_max' => 70.0,
            'volume_recent_minutes' => 5,
            'volume_base_minutes' => 30,
            'volume_ratio' => 1.0,
        ],

        // Regras de rompimento (#181): caixa das últimas `box_minutes` velas antes da atual, comprimida
        // quando a faixa média por minuto dela é no máximo `max_squeeze` vezes a das `context_minutes`
        // antes. "Rompimento confirmado": fecha acima da caixa com `volume_ratio` vezes o volume médio.
        // "Pressão antes do rompimento": mínimas subindo, `min_touches` velas na faixa `touch_zone` da
        // altura sob a máxima, preço até `near_box` da altura abaixo dela e volume dos últimos
        // `volume_recent_minutes` `volume_rise` vezes o da caixa. Alvo pela altura da caixa, no mínimo
        // `min_target_costs` vezes o custo da ida e volta; até `max_hold_minutes`.
        'breakout' => [
            'box_minutes' => 30,
            'context_minutes' => 120,
            'max_squeeze' => 0.7,
            'volume_ratio' => 2.0,
            'touch_zone' => 0.15,
            'min_touches' => 3,
            'near_box' => 0.25,
            'volume_recent_minutes' => 5,
            'volume_rise' => 1.3,
            'min_target_costs' => 2.0,
            'max_hold_minutes' => 60,
        ],

        // micro:backtest: parte final do período usada só para validar, e o que a validação exige.
        'backtest' => [
            'validation_share' => 0.3,
            'min_trades' => (int) env('TRADING_MICRO_BACKTEST_MIN_TRADES', 100),
        ],
    ],

    'binance' => [
        // Signed requests (orders, account). BINANCE_TESTNET=true aponta para o
        // testnet spot (sem dinheiro real); BINANCE_API_URL troca a base à mão.
        // A api.binance.com responde 451 em regiões bloqueadas (nuvem, Forge).
        'testnet' => (bool) env('BINANCE_TESTNET', false),
        'base_url' => env('BINANCE_API_URL', env('BINANCE_BASE_URL', env('BINANCE_TESTNET', false) ? 'https://testnet.binance.vision' : 'https://api.binance.com')),
        // Public candles only. data-api.binance.vision serves the same /api/v3
        // market data and answers where api.binance.com returns 451.
        'market_data_url' => env('BINANCE_MARKET_DATA_URL', 'https://data-api.binance.vision'),
        // WebSocket de negócios em tempo real; o espelho público data-stream.binance.vision
        // responde onde stream.binance.com devolve 451.
        // De quantos em quantos segundos o coletor confere se a lista de pares mudou (ativo novo ou removido) e reconecta.
        'stream_reload_seconds' => (int) env('BINANCE_STREAM_RELOAD_SECONDS', 30),
        'stream_url' => env('BINANCE_STREAM_URL', 'wss://data-stream.binance.vision'),
        'recv_window' => (int) env('BINANCE_RECV_WINDOW', 5000),
        // Chamadas assinadas: conexão que não abre em poucos segundos é refeita na hora com conexão nova,
        // e consulta ou cancelamento sem resposta repetem uma vez; envio de ordem não repete sozinho (#198).
        'connect_timeout' => (int) env('BINANCE_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('BINANCE_TIMEOUT', 5),
        'connect_retries' => (int) env('BINANCE_CONNECT_RETRIES', 2),
        'read_retries' => (int) env('BINANCE_READ_RETRIES', 1),
        'retry_pause_ms' => (int) env('BINANCE_RETRY_PAUSE_MS', 250),
    ],

    // Bridge in front of the MT5 terminal (MT5 has no official REST API).
    'mt5' => [
        'gateway_url' => env('MT5_GATEWAY_URL'),
        'gateway_token' => env('MT5_GATEWAY_TOKEN'),
    ],

];
