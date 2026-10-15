<?php

use App\Enums\AgentRole;

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | "fast" runs news triage and the routine voters; "strong" runs the skeptic
    | and deep news analysis.
    |
    */

    'models' => [
        'fast' => env('AGENTS_FAST_MODEL', 'claude-haiku-5-5'),
        'strong' => env('AGENTS_STRONG_MODEL', 'claude-opus-5-5'),
    ],

    /*
    | "anthropic" calls Claude with ANTHROPIC_API_KEY; "fake" answers hold to
    | every question so the app runs without a key.
    */

    'driver' => env('AGENTS_DRIVER', 'fake'),

    'timeout_seconds' => (int) env('AGENTS_TIMEOUT', 120),

    /*
    | US dollars per million tokens, used to record the cost of each vote.
    */

    'pricing' => [
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    'roles' => [
        AgentRole::Technical->value => [
            'model' => 'fast',
            'prompt' => 'Você é o analista técnico. Avalie tendência, suportes e resistências, volume e volatilidade a partir dos candles e indicadores do cenário. Ignore notícias. Proponha stop e alvo coerentes com a volatilidade.',
        ],
        AgentRole::News->value => [
            'model' => 'fast',
            'prompt' => 'Você é o analista de notícias e sentimento. Use apenas as notícias do cenário. Diga se cada fato é novo ou já precificado, comparando com o movimento do preço desde a publicação. Sem notícia relevante, vote hold.',
        ],
        AgentRole::Fundamentals->value => [
            'model' => 'fast',
            'prompt' => 'Você é o analista de fundamentos (ações da B3) e de dados on-chain (cripto). Considere resultados, eventos corporativos, agenda, fluxo de exchanges e liquidez quando estiverem no cenário. Sem dados, vote hold com confiança baixa.',
        ],
        AgentRole::Quant->value => [
            'model' => 'fast',
            'prompt' => 'Você representa o modelo quantitativo. Traduza a previsão do serviço de ML presente no cenário em um voto. Sem previsão disponível, vote hold com confiança zero.',
        ],
        AgentRole::Skeptic->value => [
            'model' => 'strong',
            'prompt' => 'Você é o cético. Procure o argumento mais forte contra a operação proposta e os riscos que os outros ignoraram. Só vote a favor se não encontrar objeção relevante.',
        ],
        AgentRole::Risk->value => [
            'model' => 'fast',
            'prompt' => 'Você é o agente de risco. Avalie tamanho da posição, exposição atual, correlação e se o stop é aceitável. Use veto=true apenas quando a operação violar uma regra de risco clara.',
        ],
    ],

];
