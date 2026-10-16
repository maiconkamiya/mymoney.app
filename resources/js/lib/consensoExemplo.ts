import { ref } from 'vue';
import { assets, candles, ASSETS, type AssetId } from '@/lib/mercado';

/*
  Exemplo do consenso calculado no navegador sobre os candles e negócios reais.
  Segue a regra do ConsensusEngine do app: voto vira valor em [-1, 1], a nota é a média,
  aprovação exige |score| >= 0,35 e 4 votos na mesma direção; o veto de risco recusa sempre.
  Nenhum modelo de IA é chamado nesta página.
*/

export type Action = 'compra' | 'venda' | 'manter';
export interface AgentVote {
    agent: string;
    action: Action;
    confidence: number;
    reason: string;
    veto?: boolean;
}
export interface Round {
    id: number;
    asset: AssetId;
    pair: string;
    price: number;
    at: number;
    votes: AgentVote[];
    score: number;
    approved: boolean;
    verdict: string;
}

const THRESHOLD = 0.35;
const MIN_AGREEING = 4;

const ema = (xs: number[], n: number) => {
    const k = 2 / (n + 1);
    return xs.reduce(
        (acc, x, i) => (i === 0 ? x : x * k + acc * (1 - k)),
        xs[0],
    );
};
const clamp = (x: number) => Math.max(0, Math.min(1, x));
const fmt = (x: number, d = 2) =>
    x.toLocaleString('pt-BR', {
        minimumFractionDigits: d,
        maximumFractionDigits: d,
    });

function decide(id: AssetId): Omit<Round, 'id'> {
    const closes = candles[id].value.map((c) => c[4]);
    const last = closes[closes.length - 1];
    const returns = closes
        .slice(-31)
        .map((c, i, arr) => (i ? c / arr[i - 1] - 1 : 0))
        .slice(1);
    const vol =
        Math.sqrt(
            returns.reduce((s, r) => s + r * r, 0) /
                Math.max(returns.length, 1),
        ) * 100;

    // Técnico: cruzamento das médias de 9 e 21 minutos.
    const gap =
        (ema(closes.slice(-60), 9) / ema(closes.slice(-60), 21) - 1) * 100;
    const tech: AgentVote = {
        agent: 'Técnico',
        action: Math.abs(gap) < 0.02 ? 'manter' : gap > 0 ? 'compra' : 'venda',
        confidence: clamp(Math.abs(gap) * 6),
        reason: `Média de 9 min ${gap >= 0 ? 'acima' : 'abaixo'} da de 21 min (${fmt(gap, 3)}%).`,
    };

    // Notícias: o protótipo não lê notícias, então o agente se abstém como no app sem notícia relevante.
    const news: AgentVote = {
        agent: 'Notícias',
        action: 'manter',
        confidence: 0,
        reason: 'Sem notícia relevante no período.',
    };

    // Fluxo: proporção de compras agressoras nos últimos negócios.
    const recent = assets[id].trades.slice(0, 30);
    const buyShare = recent.length
        ? recent.filter((t) => t.side === 'compra').length / recent.length
        : 0.5;
    const flow: AgentVote = {
        agent: 'Fluxo',
        action:
            Math.abs(buyShare - 0.5) < 0.1
                ? 'manter'
                : buyShare > 0.5
                  ? 'compra'
                  : 'venda',
        confidence: clamp(Math.abs(buyShare - 0.5) * 2.5),
        reason: `${Math.round(buyShare * 100)}% dos últimos ${recent.length || 0} negócios foram compras agressoras.`,
    };

    // Quant: retorno dos últimos 15 minutos em relação à volatilidade.
    const r15 = (last / closes[closes.length - 16] - 1) * 100;
    const z = vol > 0 ? r15 / (vol * Math.sqrt(15)) : 0;
    const quant: AgentVote = {
        agent: 'Quant',
        action: Math.abs(z) < 0.3 ? 'manter' : z > 0 ? 'compra' : 'venda',
        confidence: clamp(Math.abs(z) / 1.5),
        reason: `Retorno de 15 min de ${fmt(r15)}% (${fmt(z)} desvios).`,
    };

    // Cético: discorda quando técnico e quant não apontam para o mesmo lado.
    const aligned = tech.action === quant.action && tech.action !== 'manter';
    const skeptic: AgentVote = aligned
        ? {
              agent: 'Cético',
              action: tech.action,
              confidence: 0.35,
              reason: 'Não achei objeção forte: tendência e momento concordam.',
          }
        : {
              agent: 'Cético',
              action: 'manter',
              confidence: 0.6,
              reason: 'Tendência e momento discordam; o sinal é fraco.',
          };

    // Risco: veta com volatilidade alta, senão acompanha a maioria com cautela.
    const highVol = vol > 0.25;
    const majority = [tech, flow, quant]
        .map((v) => (v.action === 'compra' ? 1 : v.action === 'venda' ? -1 : 0))
        .reduce<number>((a, b) => a + b, 0);
    const risk: AgentVote = highVol
        ? {
              agent: 'Risco',
              action: 'manter',
              confidence: 1,
              veto: true,
              reason: `Volatilidade de ${fmt(vol, 3)}% por minuto passa do limite de 0,25%.`,
          }
        : {
              agent: 'Risco',
              action:
                  majority > 0 ? 'compra' : majority < 0 ? 'venda' : 'manter',
              confidence: 0.4,
              reason: `Volatilidade de ${fmt(vol, 3)}% por minuto, dentro do limite.`,
          };

    const votes = [tech, news, flow, quant, skeptic, risk];
    const value = (v: AgentVote) =>
        v.action === 'compra'
            ? v.confidence
            : v.action === 'venda'
              ? -v.confidence
              : 0;
    const score =
        Math.round(
            (votes.reduce((s, v) => s + value(v), 0) / votes.length) * 100,
        ) / 100;
    const dir = Math.sign(score);
    const side: Action = dir > 0 ? 'compra' : 'venda';
    const agreeing =
        dir === 0 ? 0 : votes.filter((v) => v.action === side).length;

    let approved = false;
    let verdict: string;
    if (risk.veto) verdict = 'Recusado pelo veto do agente de risco.';
    else if (Math.abs(score) < THRESHOLD)
        verdict = `Recusado: nota ${fmt(score)} abaixo do limite ${fmt(THRESHOLD)}.`;
    else if (agreeing < MIN_AGREEING)
        verdict = `Recusado: só ${agreeing} de 6 votos concordam (mínimo ${MIN_AGREEING}).`;
    else {
        approved = true;
        verdict = `Aprovado: ${side} simulada com nota ${fmt(score)}.`;
    }

    return {
        asset: id,
        pair: assets[id].pair,
        price: last,
        at: Date.now(),
        votes,
        score,
        approved,
        verdict,
    };
}

export const rounds = ref<Round[]>([]);
let seq = 0;
let cursor = 0;

export function nextRound() {
    if (ASSETS.some((a) => candles[a.id].value.length < 60)) return;
    const id = ASSETS[cursor++ % ASSETS.length].id;
    rounds.value = [{ id: ++seq, ...decide(id) }, ...rounds.value].slice(0, 4);
}
