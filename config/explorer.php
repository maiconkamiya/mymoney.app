<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Explorador de oportunidades
    |--------------------------------------------------------------------------
    |
    | Varre todos os pares spot em USDT da Binance com filtros baratos e
    | determinísticos, ranqueia os melhores e, opcionalmente, pede a um modelo
    | barato uma explicação de uma linha por par. Nunca compra: no máximo manda
    | as melhores aos agentes, e a compra continua no consenso e na guarda de risco.
    |
    */

    'enabled' => (bool) env('EXPLORER_ENABLED', true),

    'interval_minutes' => max(5, min(59, (int) env('EXPLORER_INTERVAL_MINUTES', 15))),

    // Horas UTC em que a varredura roda, como "8-22"; vazio = o dia todo.
    'hours' => (string) env('EXPLORER_HOURS', ''),

    'filters' => [
        'min_quote_volume' => (float) env('EXPLORER_MIN_VOLUME_USDT', 2_000_000),
        // O custo de ida e volta é ~0,30%: spread acima disso come a margem.
        'max_spread_pct' => (float) env('EXPLORER_MAX_SPREAD_PCT', 0.15),
        'min_change_pct' => (float) env('EXPLORER_MIN_CHANGE_PCT', 2.0),
        // Acima disso o par já disparou: entrar agora é chegar tarde.
        'max_change_pct' => (float) env('EXPLORER_MAX_CHANGE_PCT', 15.0),
        // Preço mais de N ATR (1h) acima da EMA21 de 1h = alta esticada.
        'max_extension_atr' => (float) env('EXPLORER_MAX_EXTENSION_ATR', 3.0),
        'stable_bases' => ['USDC', 'FDUSD', 'TUSD', 'BUSD', 'DAI', 'USDP', 'USDD', 'PYUSD', 'EUR', 'EURI', 'AEUR', 'XUSD', 'USD1', 'USDE', 'BFUSD', 'GBP', 'TRY', 'BRL', 'PAXG', 'WBTC', 'WBETH', 'BNSOL'],
    ],

    // Pares que passam nos filtros e ganham a segunda passada com velas de 1h (2 de peso cada na Binance).
    'deep_candidates' => (int) env('EXPLORER_DEEP_CANDIDATES', 30),

    // Quantos ficam salvos e aparecem na tela.
    'top' => (int) env('EXPLORER_TOP', 10),

    /*
    | Escore de rompimento de alta (0 a 100), sem IA, sobre as velas de 1 h já
    | baixadas. Os sinais vêm de App\Trading\Breakout\BreakoutSignals, o mesmo
    | cálculo das regras de rompimento das micro operações. Pesos iniciais
    | simples, somando 1: calibrar com dados reais de produção.
    |
    | - compression: faixa das últimas velas menor que a média (squeeze).
    | - volume: volume da última vela fechada contra a média recente.
    | - structure: mínimas subindo e resistência testada várias vezes.
    | - donchian: fechamento perto ou acima da máxima do canal.
    | - expansion: a última vela abriu a faixa (ATR em expansão).
    |
    | `min_score` é o limiar de "Recomendado" (Configurações > Custos e serviços).
    | `order_by` = breakout ordena a lista pelo escore; score volta à nota antiga.
    */

    'breakout' => [
        'min_score' => (int) env('EXPLORER_BREAKOUT_MIN_SCORE', 60),
        'order_by' => env('EXPLORER_ORDER_BY', 'breakout'),
        'weights' => [
            'compression' => 0.25,
            'volume' => 0.25,
            'structure' => 0.20,
            'donchian' => 0.15,
            'expansion' => 0.15,
        ],
    ],

    'ai' => [
        'enabled' => (bool) env('EXPLORER_AI_ENABLED', true),
        'model' => env('EXPLORER_AI_MODEL', env('AGENTS_FAST_MODEL', 'claude-haiku-5-5')),
    ],

    /*
    | Envio automático aos agentes, só nas carteiras que ligarem a opção na tela
    | de Ativos. Cada envio custa uma rodada completa do swarm.
    */

    'swarm' => [
        'top' => (int) env('EXPLORER_SWARM_TOP', 3),
        'min_score' => (float) env('EXPLORER_SWARM_MIN_SCORE', 50),
        'cooldown_hours' => (int) env('EXPLORER_SWARM_COOLDOWN_HOURS', 6),
        'max_per_day' => (int) env('EXPLORER_SWARM_MAX_PER_DAY', 12),
    ],

    // Pares cadastrados só pelo explorador saem sozinhos depois disso, se ninguém acompanhou.
    'discovered_ttl_hours' => (int) env('EXPLORER_DISCOVERED_TTL_HOURS', 24),

    // Varreduras guardadas para conferir depois se as sugestões andaram.
    'keep_days' => (int) env('EXPLORER_KEEP_DAYS', 14),

];
