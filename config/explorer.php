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
