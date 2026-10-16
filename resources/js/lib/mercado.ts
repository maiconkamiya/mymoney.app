import { reactive, shallowRef, ref, computed, type ShallowRef } from 'vue';
import btc1m from '@/data/mercado/btc-1m.json';
import eth1m from '@/data/mercado/eth-1m.json';
import sol1m from '@/data/mercado/sol-1m.json';
import btcTrades from '@/data/mercado/btc-trades.json';
import ethTrades from '@/data/mercado/eth-trades.json';
import solTrades from '@/data/mercado/sol-trades.json';

/*
  Cotações reais da Binance (pares em real).
  1. Tenta o WebSocket público da Binance: com rede livre (npm run dev), os dados chegam ao vivo.
  2. Se a conexão não abrir em 5 s (o Artifact bloqueia rede externa), reproduz os negócios reais
     gravados em 16/10/2026, no ritmo original com pausas longas encurtadas.
*/

export type AssetId = 'btc' | 'eth' | 'sol';
/** [abertura ms, abertura, máxima, mínima, fechamento, volume] */
export type Candle = [number, number, number, number, number, number];
/** [hora ms, preço, quantidade, vendedor agressor] */
type RawTrade = [number, number, number, boolean];

export interface Trade {
    id: number;
    t: number;
    price: number;
    qty: number;
    side: 'compra' | 'venda';
}

export interface AssetState {
    id: AssetId;
    name: string;
    pair: string;
    price: number;
    dir: 0 | 1 | -1;
    tick: number;
    trades: Trade[];
}

const MINUTE = 60_000;
const KEEP = 180;

export const ASSETS: {
    id: AssetId;
    name: string;
    pair: string;
    stream: string;
}[] = [
    { id: 'btc', name: 'Bitcoin', pair: 'BTC/BRL', stream: 'btcbrl@aggTrade' },
    { id: 'eth', name: 'Ethereum', pair: 'ETH/BRL', stream: 'ethbrl@aggTrade' },
    { id: 'sol', name: 'Solana', pair: 'SOL/BRL', stream: 'solbrl@aggTrade' },
];

const recordedCandles: Record<AssetId, Candle[]> = {
    btc: btc1m as Candle[],
    eth: eth1m as Candle[],
    sol: sol1m as Candle[],
};
const recordedTrades: Record<AssetId, RawTrade[]> = {
    btc: btcTrades as RawTrade[],
    eth: ethTrades as RawTrade[],
    sol: solTrades as RawTrade[],
};

export type FeedMode = 'conectando' | 'ao-vivo' | 'reproducao';

export const mode = ref<FeedMode>('conectando');
export const replayDate = new Date(
    recordedCandles.btc[recordedCandles.btc.length - 1][0] + MINUTE,
);

export const candles: Record<AssetId, ShallowRef<Candle[]>> = {
    btc: shallowRef<Candle[]>([]),
    eth: shallowRef<Candle[]>([]),
    sol: shallowRef<Candle[]>([]),
};

export const assets = reactive<Record<AssetId, AssetState>>(
    Object.fromEntries(
        ASSETS.map((a) => [
            a.id,
            {
                id: a.id,
                name: a.name,
                pair: a.pair,
                price: 0,
                dir: 0,
                tick: 0,
                trades: [],
            },
        ]),
    ) as unknown as Record<AssetId, AssetState>,
);

let tradeSeq = 0;

function applyTrade(
    id: AssetId,
    t: number,
    price: number,
    qty: number,
    sellerAggressor: boolean,
) {
    const list = candles[id].value;
    const minute = Math.floor(t / MINUTE) * MINUTE;
    const last = list[list.length - 1];
    if (last && last[0] === minute) {
        last[2] = Math.max(last[2], price);
        last[3] = Math.min(last[3], price);
        last[4] = price;
        last[5] += qty;
    } else if (!last || minute > last[0]) {
        list.push([minute, price, price, price, price, qty]);
        if (list.length > KEEP) list.shift();
    }
    // Nova referência para os computeds (gráfico, máxima e mínima) recalcularem.
    candles[id].value = list.slice();

    const a = assets[id];
    a.dir = price > a.price ? 1 : price < a.price ? -1 : a.dir;
    a.price = price;
    a.tick++;
    a.trades.unshift({
        id: ++tradeSeq,
        t,
        price,
        qty,
        side: sellerAggressor ? 'venda' : 'compra',
    });
    if (a.trades.length > 40) a.trades.pop();
}

/** Variação desde o primeiro candle visível (cerca de 3 horas). */
export function changePct(id: AssetId) {
    const list = candles[id].value;
    if (!list.length) return 0;
    return (assets[id].price / list[0][1] - 1) * 100;
}

/* ---------- reprodução dos negócios gravados ---------- */

const timers: number[] = [];

function startReplay() {
    mode.value = 'reproducao';
    for (const { id } of ASSETS) replayAsset(id);
}

function replayAsset(id: AssetId) {
    const all = recordedCandles[id];
    const trades = recordedTrades[id];
    // Começa onde os negócios gravados encontram o gráfico, deixando pelo menos 60 candles antes.
    const minStart = all[60][0];
    const firstTrade = trades.findIndex((tr) => tr[0] >= minStart);
    const startIdx =
        firstTrade === -1 ? Math.max(0, trades.length - 200) : firstTrade;
    const startTime = trades[startIdx][0];

    const run = () => {
        candles[id].value = all
            .filter((c) => c[0] + MINUTE <= startTime)
            .map((c) => [...c] as Candle);
        const a = assets[id];
        a.trades = [];
        a.price = candles[id].value[candles[id].value.length - 1][4];
        let i = startIdx;
        const step = () => {
            if (i >= trades.length) {
                timers.push(window.setTimeout(run, 2500));
                return;
            }
            const [t, p, q, m] = trades[i];
            applyTrade(id, t, p, q, m);
            const next = trades[i + 1];
            i++;
            const gap = next ? next[0] - t : 0;
            // Ritmo real, com pausas longas encurtadas para no máximo 1,4 s.
            timers.push(
                window.setTimeout(step, Math.min(Math.max(gap, 140), 1400)),
            );
        };
        step();
    };
    run();
}

/* ---------- conexão ao vivo ---------- */

let socket: WebSocket | null = null;

let started = false;

export function startMarket() {
    // Painel e página inicial chamam ao montar; só uma conexão por vez.
    if (started) {
        return;
    }

    started = true;
    // Antes de conectar, mostra os candles reais gravados para a página nunca abrir vazia.
    for (const { id } of ASSETS) {
        candles[id].value = recordedCandles[id].map((c) => [...c] as Candle);
        assets[id].price =
            recordedCandles[id][recordedCandles[id].length - 1][4];
    }

    const url =
        'wss://stream.binance.com:9443/stream?streams=' +
        ASSETS.map((a) => a.stream).join('/');
    let opened = false;
    const fallback = window.setTimeout(() => {
        if (!opened) {
            socket?.close();
            startReplay();
        }
    }, 5000);
    timers.push(fallback);

    try {
        socket = new WebSocket(url);
    } catch {
        window.clearTimeout(fallback);
        startReplay();
        return;
    }

    socket.onmessage = (ev) => {
        const msg = JSON.parse(ev.data as string) as {
            stream: string;
            data: { T: number; p: string; q: string; m: boolean };
        };
        const asset = ASSETS.find((a) => a.stream === msg.stream);
        if (!asset) return;
        if (!opened) {
            opened = true;
            window.clearTimeout(fallback);
            mode.value = 'ao-vivo';
        }
        applyTrade(
            asset.id,
            msg.data.T,
            Number(msg.data.p),
            Number(msg.data.q),
            msg.data.m,
        );
    };
    socket.onerror = () => {
        if (started && !opened) {
            window.clearTimeout(fallback);
            startReplay();
        }
    };
    socket.onclose = () => {
        if (started && opened && mode.value === 'ao-vivo') {
            opened = false;
            startReplay();
        }
    };
}

export function stopMarket() {
    started = false;
    socket?.close();
    socket = null;
    timers.forEach((t) => window.clearTimeout(t));
    timers.length = 0;
    mode.value = 'conectando';
}

export const modeLabel = computed(() =>
    mode.value === 'ao-vivo'
        ? 'Ao vivo na Binance'
        : mode.value === 'reproducao'
          ? 'Reprodução de negócios reais'
          : 'Conectando à Binance',
);

/* ---------- formatação ---------- */

export const brl = (v: number, digits = 2) =>
    v.toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
export const pct = (v: number) =>
    (v >= 0 ? '+' : '') +
    v.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) +
    '%';
export const hora = (t: number) =>
    new Date(t).toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
