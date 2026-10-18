// Data (AAAA-MM-DD) do fim de negociação de um par, sem passar por fuso: o dia é o da corretora (UTC).
export function dataCurta(data: string): string {
    const [ano, mes, dia] = data.split('-');

    return `${dia}/${mes}/${ano}`;
}

export function avisoFimNegociacao(
    data: string | null,
    encerrado: boolean,
): string | null {
    if (data === null) {
        return null;
    }

    return encerrado
        ? `Negociação encerrada em ${dataCurta(data)}: o app não compra mais e vende o que houver a mercado.`
        : `Negociação encerra em ${dataCurta(data)}. À 00:00 UTC desse dia o app para de comprar e vende o que houver a mercado.`;
}
