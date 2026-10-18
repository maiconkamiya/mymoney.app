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

    /*
    | Master switch of the swarm ("Agentes em ação" in Custos e serviços). Off,
    | no signal is opened or analyzed; micro operations and the exits of open
    | positions do not depend on it.
    */

    'enabled' => (bool) env('AGENTS_ENABLED', true),

    'timeout_seconds' => (int) env('AGENTS_TIMEOUT', 120),

    /*
    | Cost switches (also in Configurações > Custos e serviços, which override
    | these values): skip the Risk vote when the first round has no direction,
    | skip the skeptic when the late-entry rule would block the buy anyway, and
    | send the scenario as compact JSON (fewer input tokens).
    */

    'risk_needs_direction' => (bool) env('AGENTS_RISK_NEEDS_DIRECTION', false),
    'skip_skeptic_on_late_entry' => (bool) env('AGENTS_SKIP_SKEPTIC_ON_LATE_ENTRY', false),
    'compact_json' => (bool) env('AGENTS_COMPACT_JSON', false),

    /*
    | While a micro cycle is open on a pair, the swarm leaves that pair alone in
    | that portfolio: no signal is opened (no tokens) and a sell approved while
    | the cycle runs is rejected. The cycle's target, stop and time decide.
    */

    'wait_micro_cycle' => (bool) env('AGENTS_WAIT_MICRO_CYCLE', true),

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
Em chart.extension está o teto provável da alta (ceiling, de onde veio em ceiling_source), a folga até ele em % e em ATR, o ganho até o teto dividido por um stop de 1,5 ATR (reward_risk), a distância do preço à EMA21 em ATR, o tamanho do movimento desde o último fundo em ATR e se o volume cai enquanto o preço sobe (volume_fading). Alta já esticada (mais de 3 ATR acima da EMA21, reward_risk abaixo de 1,5 ou volume caindo) é entrada tardia: prefira aguardar. Não ponha alvo de compra acima do teto sem um rompimento confirmado.
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
            'prompt' => 'Você é o cético. Procure o argumento mais forte contra a operação proposta e os riscos que os outros ignoraram. Em compra, use "extension": teto provável da alta (ceiling), folga até ele, reward_risk (ganho até o teto sobre um stop de 1,5 ATR), distância da EMA21 em ATR, movimento desde o último fundo em ATR e volume_fading; comprar uma alta já esticada perto do teto é um risco forte. Só vote a favor se não encontrar objeção relevante.',
        ],
        AgentRole::Risk->value => [
            'model' => 'fast',
            'prompt' => 'Você é o agente de risco. Avalie tamanho da posição, exposição atual, correlação e se o stop é aceitável. Em compra, "extension" traz o teto provável da alta, a folga até ele e o quanto o preço já subiu (em ATR acima da EMA21 e desde o último fundo): pouca folga até o teto em relação ao risco pesa contra a entrada. Use veto=true apenas quando a operação violar uma regra de risco clara.',
        ],
    ],

];
