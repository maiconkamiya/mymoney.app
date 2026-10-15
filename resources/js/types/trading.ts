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
};

export type AgentVote = {
    agent: string;
    round: number;
    action: 'buy' | 'sell' | 'hold';
    confidence: number;
    veto: boolean;
    reasons: string[];
};

export type SignalRow = {
    id: number;
    symbol: string;
    stage: string;
    side: 'buy' | 'sell' | null;
    score: number | null;
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
