export type Market = 'crypto' | 'b3';

export type PortfolioSummary = {
    id: number;
    name: string;
    market: Market;
    mode: 'backtest' | 'paper' | 'live';
    status: 'draft' | 'running' | 'paused' | 'finished';
    initial_cash: number;
    equity: number;
    cash?: number;
    starts_at: string;
    ends_at: string;
    auto_execute: boolean;
    orders_blocked?: boolean;
    orders_blocked_reason?: string | null;
    notify_live_email?: boolean;
};

export type LiveInfo = {
    enabled: boolean;
    testnet: boolean;
    max_order_value: number;
    max_daily_value: number;
    remaining_today: number;
    halted: boolean;
};

export type ChartAnalysis = {
    scenario: string | null;
    supports: number[];
    resistances: number[];
    timeframes: string[];
    omitted_timeframes: string[];
};

export type AgentVote = {
    agent: string;
    round: number;
    action: 'buy' | 'sell' | 'hold';
    confidence: number;
    veto: boolean;
    abstained: boolean;
    reasons: string[];
    analysis: ChartAnalysis | null;
};

export type SignalCalculation = {
    votes: {
        agent: string;
        action: 'buy' | 'sell' | 'hold';
        confidence: number;
        abstained: boolean;
        veto: boolean;
        weight: number | null;
        contribution: number | null;
        reason: string | null;
    }[];
    valid_votes: number;
    weight_sum: number;
    score: number;
    threshold: number;
    min_voters: number;
    agreeing: number;
    quorum: number;
    late_entry?: {
        ceiling: number;
        ceiling_source: string | null;
        room_pct: number | null;
        stop: number;
        reward_risk: number;
        min_reward_risk: number;
        ema21_distance_atr: number | null;
        max_ema21_atr: number;
        move_from_low_atr: number | null;
        volume_fading: boolean | null;
        blocked: boolean;
    };
};

export type SignalRow = {
    id: number;
    symbol: string;
    stage: string;
    side: 'buy' | 'sell' | null;
    score: number | null;
    calculation: SignalCalculation | null;
    entry_price: number | null;
    stop_price: number | null;
    target_price: number | null;
    reason: string | null;
    created_at: string;
    cost: number;
    votes: AgentVote[];
};

export type PositionRow = {
    id: number;
    symbol: string;
    qty: number;
    avg_price: number;
    stop_price: number | null;
    last_price: number | null;
    value: number | null;
    trading_ends_on: string | null;
    trading_ended: boolean;
    delisting_block: string | null;
};

export type AccountHolding = {
    coin: string;
    qty: number;
    price: number | null;
    value: number | null;
    status: string;
    reason: string | null;
};

export type BrokerOrderStatus =
    | 'pending'
    | 'filled'
    | 'partially_filled'
    | 'cancelled'
    | 'expired'
    | 'rejected';

/** Uma das últimas ordens de um par na Binance, lida na sincronização. */
export type BrokerOrder = {
    id: string;
    symbol: string;
    side: 'buy' | 'sell';
    type: string;
    status: BrokerOrderStatus;
    price: number | null;
    avg_price: number | null;
    qty: number;
    executed_qty: number;
    value: number;
    time: string | null;
    app_order_id: number | null;
    from_app: boolean;
};

export type AccountSync = {
    synced_at: string | null;
    dust: AccountHolding[];
    dust_total: number;
    not_mirrored: AccountHolding[];
    not_mirrored_total: number;
    wallets: Record<string, number> | null;
    orders: BrokerOrder[];
    order_errors: Record<string, string>;
};

export type CurvePoint = {
    date: string;
    equity: number;
    benchmark: number | null;
};

export type Performance = {
    benchmark: string | null;
    curve: CurvePoint[];
    return: number;
    benchmark_return: number | null;
    excess_return: number | null;
    max_drawdown: number;
    sharpe: number | null;
};

export type AgentScoreRow = {
    agent: string;
    signals: number;
    hits: number;
    hit_rate: number;
    weight: number;
};

export type TaxMonth = {
    month: string;
    bought: number;
    sold: number;
    fees: number;
    result: number;
};

export type PendingSignal = {
    id: number;
    portfolio_id: number;
    portfolio: string;
    symbol: string;
    side: 'buy' | 'sell' | null;
    score: number | null;
    created_at: string;
};

export type AgentState =
    | 'idle'
    | 'analyzing'
    | 'waiting'
    | 'approved'
    | 'rejected'
    | 'neutral'
    | 'abstained'
    | 'buy'
    | 'sell'
    | 'veto';

export type AgentActivityAgent = {
    role: string;
    state: AgentState;
    detail: string;
    action: 'buy' | 'sell' | 'hold' | null;
    confidence: number | null;
    round: number | null;
    veto: boolean;
};

export type AgentActivityMessage = {
    from: string;
    to: string;
    kind: 'vote' | 'revision' | 'challenge' | 'veto' | 'decision';
    state: AgentState | 'challenge';
    round: number | null;
    text: string;
};

export type AgentActivity = {
    status: 'live' | 'idle' | 'empty';
    signal: {
        id: number;
        symbol: string;
        stage: string;
        side: 'buy' | 'sell' | null;
        score: number | null;
        reason: string | null;
        updated_at: string | null;
        version: string;
    } | null;
    agents: AgentActivityAgent[];
    messages: AgentActivityMessage[];
    recent: {
        id: number;
        symbol: string;
        stage: string;
        side: 'buy' | 'sell' | null;
        updated_at: string | null;
    }[];
    generated_at: string;
};

/** What an agent really did, as announced the moment it happened. */
export type AgentLiveEvent = { at: string; signal_id: number } & (
    | {
          type: 'cycle.started';
          signal: { id: number; symbol: string; stage: string };
      }
    | { type: 'agent.started'; role: string; round: number }
    | {
          type: 'agent.voted';
          role: string;
          round: number;
          action: 'buy' | 'sell' | 'hold';
          confidence: number;
          veto: boolean;
          abstained: boolean;
          text: string | null;
      }
    | { type: 'consensus.decided'; snapshot: AgentActivity }
    | { type: 'order.started'; role: string }
    | { type: 'order.finished'; snapshot: AgentActivity }
);

/** [abertura ms, abertura, máxima, mínima, fechamento, volume] */
export type ChartCandle = [number, number, number, number, number, number];

export type ChartAsset = {
    symbol: string;
    name: string | null;
    quote_currency: string;
    series: ChartCandle[];
    /** Teto provável da alta no último sinal de compra das últimas 2 horas. */
    ceiling: number | null;
    position: {
        qty: number;
        avg_price: number;
        stop_price: number | null;
        /** Venda registrada na corretora, ou o alvo da posição sem ela. */
        target_price: number | null;
        sell_registered: boolean;
    } | null;
};

export type PortfolioCharts = {
    market_open: boolean;
    phase: string;
    assets: ChartAsset[];
};

export type MicroCycleRow = {
    id: number;
    symbol: string;
    status: 'open' | 'closed';
    qty: number;
    entry_price: number;
    target_price: number;
    stop_price: number;
    exit_price: number | null;
    resting_sell_price: number | null;
    sell_failure: string | null;
    fees: number;
    net_pnl: number | null;
    exit_reason: string | null;
    opened_at: string;
    closed_at: string | null;
};

export type MicroOverview = {
    enabled: boolean;
    order_value: number;
    net_margin_pct: number;
    gross_margin_pct: number;
    round_trip_cost_pct: number;
    min_order_value: number;
    daily_target: number | null;
    daily_operations: number | null;
    wallet_pct: number;
    wallet_budget: {
        pct: number;
        limit: number;
        used: number;
        available: number;
    };
    daily_loss_limit: number | null;
    state: { code: string; label: string };
    last_block: string | null;
    last_run: string | null;
    scheduler_stale: boolean;
    margin_warning: string | null;
    skipped: { symbol: string; reason: string }[];
    today: {
        opened: number;
        cycles: number;
        wins: number;
        net_pnl: number;
        fees: number;
    };
    focus: MicroFocusRow[];
    cycles: MicroCycleRow[];
    liquidation: MicroLiquidationRow[];
    liquidation_blocked: string | null;
    sell_fee_rate: number;
};

export type MicroFocusRow = {
    symbol: string;
    atr_pct: number;
    reach_pct: number;
    entry: boolean;
    reason: string;
    blocked: string | null;
    checks?: { key: string; label: string; ok: boolean; detail: string }[];
};

export type MicroLiquidationRow = {
    asset_id: number;
    symbol: string;
    marked: boolean;
    qty: number;
    cost: number | null;
    sell_from: number | null;
    price: number | null;
    resting_sell_price: number | null;
    sell_failure: { reason: string; at: string; retry_at: string } | null;
    sell_skip: string | null;
    odds: MicroTargetOdds | null;
};

/** Frequência, nos últimos 30 dias, de o par subir até o valor da venda dentro da janela. */
export type MicroTargetOdds = {
    target: number;
    pct: number | null;
    distance_pct: number;
    distance_atr: number | null;
    minutes: number;
    samples: number;
    reason: string;
};

export type AssetRow = {
    id: number;
    symbol: string;
    market: Market;
    name: string | null;
    lot_size: string;
    tick_size: string;
    min_qty: string | null;
    min_notional: string | null;
    discovered: boolean;
    trading_ends_on: string | null;
    trading_ended: boolean;
    blockers: string[];
};

export type Opportunity = {
    id: number;
    rank: number;
    symbol: string;
    base: string;
    score: number;
    price: number;
    change_24h_pct: number;
    quote_volume: number;
    spread_pct: number;
    volume_accel: number | null;
    breakout: boolean;
    extension_atr: number | null;
    reasons: string[];
    ai_note: string | null;
    swarm_sent_at: string | null;
    status: 'registered' | 'discovered' | null;
};

export type ExplorerPortfolio = {
    id: number;
    name: string;
    mode: string;
    running: boolean;
    auto_swarm: boolean;
};

export type ExplorerData = {
    enabled: boolean;
    interval_minutes: number;
    swarm_top: number;
    swarm_min_score: number;
    swarm_max_per_day: number;
    swarm_cooldown_hours: number;
    scanned_at: string | null;
    pairs_seen: number | null;
    pairs_passed: number | null;
    ai_model: string | null;
    error: string | null;
    opportunities: Opportunity[];
    portfolios: ExplorerPortfolio[];
};

export type AssetSearchResult = {
    symbol: string;
    name: string | null;
    registered: boolean;
};

export type AvailableAsset = {
    id: number;
    symbol: string;
    name: string | null;
    trading_ends_on: string | null;
    trading_ended: boolean;
};

export type TokenPeriod = {
    input: number;
    output: number;
    total: number;
    cost: number;
    calls: number;
};

export type TokenUsage = {
    /** Primeira chamada com tokens gravada nesta carteira (null antes da primeira). */
    since: string | null;
    today: TokenPeriod;
    week: TokenPeriod;
    month: TokenPeriod;
    estimate: Omit<TokenPeriod, 'calls'> & {
        days: number;
        days_in_month: number;
    };
};
