export type Candle = {
    time: number;
    open: number;
    high: number;
    low: number;
    close: number;
    volume: number;
};

export type MarketAsset = {
    id: number;
    symbol: string;
    quote_currency: string;
    tick_size: string;
    candles: Candle[];
};

export type CollectorSnapshot = {
    connected: boolean;
    stalled: boolean;
    connected_at: number | null;
    last_message_at: number | null;
    lag_seconds: number | null;
    symbols: Record<string, { price: number; at: number }>;
};

export type CandleUpdatedEvent = {
    symbol: string;
    timeframe: string;
    candle: Candle;
    closed: boolean;
    event_at: number;
};
