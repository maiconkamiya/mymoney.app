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

export type AgentVote = {
    agent: string;
    round: number;
    action: 'buy' | 'sell' | 'hold';
    confidence: number;
    veto: boolean;
    abstained: boolean;
    reasons: string[];
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
    position: {
        qty: number;
        avg_price: number;
        stop_price: number | null;
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
    daily_loss_limit: number | null;
    state: { code: string; label: string };
    last_block: string | null;
    today: { cycles: number; wins: number; net_pnl: number; fees: number };
    focus: { symbol: string; atr_pct: number; entry: boolean; reason: string }[];
    cycles: MicroCycleRow[];
};
