<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Página do ativo: histórico, sentimento e análise do dia
    |--------------------------------------------------------------------------
    |
    | O histórico de preços vem das velas públicas da Binance e o sentimento do
    | índice Fear & Greed (alternative.me); os dois são gratuitos e sem chave.
    | A análise do dia é a única parte com IA e começa desligada.
    |
    */

    // Minutos que o histórico de um par fica guardado antes de buscar de novo.
    'history_cache_minutes' => (int) env('INSIGHTS_HISTORY_CACHE_MINUTES', 5),

    'fear_greed' => [
        'url' => env('INSIGHTS_FEAR_GREED_URL', 'https://api.alternative.me/fng/'),
        // O índice muda uma vez por dia.
        'cache_minutes' => (int) env('INSIGHTS_FEAR_GREED_CACHE_MINUTES', 60),
    ],

    'ai' => [
        'enabled' => (bool) env('INSIGHTS_AI_ENABLED', false),
        'model' => env('INSIGHTS_AI_MODEL', env('AGENTS_FAST_MODEL', 'claude-haiku-5-5')),
        // Hora UTC da análise automática dos ativos acompanhados.
        'daily_at' => (string) env('INSIGHTS_AI_DAILY_AT', '11:00'),
        // Um pedido de "Atualizar" dentro desse intervalo devolve a análise que já existe.
        'min_interval_minutes' => (int) env('INSIGHTS_AI_MIN_INTERVAL_MINUTES', 15),
    ],

];
