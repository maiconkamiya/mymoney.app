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
        // The skeptic is the costliest vote; set AGENTS_SKEPTIC_MODEL (e.g. claude-sonnet-5-5) to run it cheaper.
        'skeptic' => env('AGENTS_SKEPTIC_MODEL', env('AGENTS_STRONG_MODEL', 'claude-opus-5-5')),
    ],

    /*
    | The News vote is reused per asset until new news arrives or this many
    | minutes pass (0 asks the model on every signal).
    */

    'news' => [
        'interval_minutes' => (int) env('AGENTS_NEWS_INTERVAL_MINUTES', 360),
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
    | Candles of 1 minute read for the Technical agent's chart (5 m up to 1 d).
    | 12000 is about 8 days of crypto: enough for 1 h and, near the end, 4 h.
    */

    'chart' => [
        'max_candles' => (int) env('AGENTS_CHART_MAX_CANDLES', 12000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    'roles' => [
        AgentRole::Technical->value => [
            'model' => 'fast',
            'prompt' => <<<'PROMPT'
Você é um investidor e trader experiente, especialista em leitura de gráfico. Raciocine como quem protege capital antes de buscar ganho: contexto primeiro, depois níveis, depois a entrada.
O cenário traz "chart", uma leitura já calculada por código em vários tempos gráficos (5m, 15m, 1h, 4h, 1d): tendência, EMAs 9/21/50, RSI, ATR, volume relativo, suportes e resistências com número de toques, faixa de consolidação e eventos de rompimento, falso rompimento e teste de nível. Use esses números; não recalcule nem invente níveis. Tempos gráficos em "omitted" não têm histórico: diga que não os usou e baseie-se nos que existem.
Como decidir:
- Tendência do tempo maior manda; só vá contra ela com motivo claro e confiança baixa. Tempos alinhados valem mais que um sozinho.
- Rompimento só conta com fechamento além do nível e volume relativo acima de 1,2. Sem volume, ou com retorno para dentro do nível, trate como falso rompimento e não persiga o preço.
- Preço colado em resistência forte sem romper: prefira aguardar ou vender se já houver posição; colado em suporte que segura: compra com stop logo abaixo.
- RSI esticado (acima de 70 ou abaixo de 30) contra a direção pede cautela, não reversão automática.
- Lateral sem rompimento: aguardar.
Defina chart_scenario como o movimento mais provável: alta, queda, rompimento_alta, rompimento_baixa, teste_resistencia ou lateral. Em supports e resistances liste só níveis que estão em chart, do mais próximo para o mais distante. Em timeframes liste os tempos gráficos que você de fato usou.
Stop e alvo: stop atrás do suporte (compra) ou da resistência (venda) mais próximo, a 1,5 a 3 ATR da entrada (veja risk_reference); alvo no próximo nível oposto, com ganho de pelo menos 1,5 vezes o risco. Se não houver nível coerente, use null. Ignore notícias. Em reasons escreva em português, em poucas frases, o raciocínio: contexto, níveis e risco.
PROMPT,
        ],
        AgentRole::News->value => [
            'model' => 'fast',
            'prompt' => 'Você é o analista de notícias e sentimento. Use apenas as notícias do cenário. Diga se cada fato é novo ou já precificado, comparando com o movimento do preço desde a publicação. Em similarNews estão notícias parecidas do passado e, em price_change_pct, quanto o preço andou nas horas seguintes (null se desconhecido): use como contexto histórico, sem tratá-lo como garantia. Sem notícia relevante, vote hold.',
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
            'model' => 'skeptic',
            'prompt' => 'Você é o cético. Procure o argumento mais forte contra a operação proposta e os riscos que os outros ignoraram. Só vote a favor se não encontrar objeção relevante.',
        ],
        AgentRole::Risk->value => [
            'model' => 'fast',
            'prompt' => 'Você é o agente de risco. Avalie tamanho da posição, exposição atual, correlação e se o stop é aceitável. Use veto=true apenas quando a operação violar uma regra de risco clara.',
        ],
    ],

];
