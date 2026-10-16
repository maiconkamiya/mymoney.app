export const stageLabels: Record<string, string> = {
    scanned: 'Escaneado',
    analyzed: 'Analisado',
    approved: 'Aprovado',
    rejected: 'Rejeitado',
    confirmed: 'Confirmado',
    executed: 'Executado',
    closed: 'Fechado',
};

export const agentLabels: Record<string, string> = {
    coordinator: 'Coordenador',
    executor: 'Executor',
    technical: 'Técnico',
    news: 'Notícias',
    fundamentals: 'Fundamentos',
    quant: 'Quantitativo',
    skeptic: 'Cético',
    risk: 'Risco',
};

export const actionLabels: Record<string, string> = {
    buy: 'Compra',
    sell: 'Venda',
    hold: 'Aguardar',
};

export const statusLabels: Record<string, string> = {
    draft: 'rascunho',
    running: 'em execução',
    paused: 'pausada',
    finished: 'encerrada',
};

export const marketLabels: Record<string, string> = {
    crypto: 'Cripto',
    b3: 'B3',
};

export function money(value: number | null | undefined, market = 'b3'): string {
    if (value === null || value === undefined) {
        return '-';
    }

    return new Intl.NumberFormat('pt-BR', {
        style: market === 'b3' ? 'currency' : 'decimal',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: market === 'b3' ? 2 : 8,
    }).format(value);
}

export function percent(value: number): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'percent',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'exceptZero',
    }).format(value);
}
