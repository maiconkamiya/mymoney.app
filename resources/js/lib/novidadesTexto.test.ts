import assert from 'node:assert/strict';
import { test } from 'node:test';

import { dividirNegrito, formatarDataDaVersao } from './novidadesTexto.ts';

test('texto sem negrito vira um trecho só', () => {
    assert.deepEqual(dividirNegrito('Cadastros novos'), [
        { texto: 'Cadastros novos', negrito: false },
    ]);
});

test('o par de ** vira trecho em negrito, com o texto antes e depois', () => {
    assert.deepEqual(dividirNegrito('**Vendas:** pedido com crédito e **preço** mínimo.'), [
        { texto: 'Vendas:', negrito: true },
        { texto: ' pedido com crédito e ', negrito: false },
        { texto: 'preço', negrito: true },
        { texto: ' mínimo.', negrito: false },
    ]);
});

test('** sem par fica como texto literal', () => {
    assert.deepEqual(dividirNegrito('promoção ** sem fechar'), [
        { texto: 'promoção ** sem fechar', negrito: false },
    ]);
});

test('HTML no texto continua texto: nada é interpretado', () => {
    const trechos = dividirNegrito('Veja <script>alert(1)</script> e **<b>negrito</b>**');

    assert.deepEqual(trechos, [
        { texto: 'Veja <script>alert(1)</script> e ', negrito: false },
        { texto: '<b>negrito</b>', negrito: true },
    ]);
});

test('texto vazio não gera trecho', () => {
    assert.deepEqual(dividirNegrito(''), []);
});

test('a data ISO vira dd/mm/aaaa, e o resto passa como veio', () => {
    assert.equal(formatarDataDaVersao('2026-09-30'), '30/09/2026');
    assert.equal(formatarDataDaVersao('setembro de 2026'), 'setembro de 2026');
    assert.equal(formatarDataDaVersao(null), '');
});
