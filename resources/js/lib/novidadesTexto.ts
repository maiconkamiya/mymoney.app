/**
 * Texto do CHANGELOG para a página "Novidades".
 *
 * O servidor entrega cada item como TEXTO PURO. O único realce permitido é o
 * negrito `**assim**`, e ele vira trechos que o Vue desenha com `<strong>` no
 * template. Nada aqui produz HTML: não há `v-html`, então um `<script>` ou um
 * `<b>` escrito no CHANGELOG aparece como texto na tela e nunca é executado.
 */
export type TrechoDeTexto = {
    texto: string;
    negrito: boolean;
};

/**
 * Divide `texto` nos pares de `**`. Um `**` sem par fica como texto literal.
 */
export function dividirNegrito(texto: string): TrechoDeTexto[] {
    const trechos: TrechoDeTexto[] = [];
    const padrao = /\*\*([^*]+?)\*\*/g;
    let cursor = 0;

    for (const par of texto.matchAll(padrao)) {
        const inicio = par.index ?? 0;

        if (inicio > cursor) {
            trechos.push({
                texto: texto.slice(cursor, inicio),
                negrito: false,
            });
        }

        trechos.push({ texto: par[1], negrito: true });
        cursor = inicio + par[0].length;
    }

    if (cursor < texto.length) {
        trechos.push({ texto: texto.slice(cursor), negrito: false });
    }

    return trechos;
}

/**
 * `2026-09-30` -> `30/09/2026`. Devolve o texto como veio quando não é uma
 * data ISO (o CHANGELOG é texto livre).
 */
export function formatarDataDaVersao(data: string | null): string {
    const partes =
        data === null ? null : /^(\d{4})-(\d{2})-(\d{2})$/.exec(data);

    return partes === null || data === null
        ? (data ?? '')
        : `${partes[3]}/${partes[2]}/${partes[1]}`;
}
