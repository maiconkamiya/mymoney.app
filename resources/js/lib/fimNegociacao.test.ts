import assert from 'node:assert/strict';
import { test } from 'node:test';

import { avisoFimNegociacao, dataCurta } from './fimNegociacao.ts';

test('a data sai no formato brasileiro sem mudar de dia pelo fuso', () => {
    assert.equal(dataCurta('2026-10-20'), '20/10/2026');
});

test('sem data não há aviso', () => {
    assert.equal(avisoFimNegociacao(null, false), null);
});

test('antes da data o aviso diz quando o app vende', () => {
    assert.match(
        avisoFimNegociacao('2026-10-20', false) ?? '',
        /encerra em 20\/10\/2026\. À 00:00 UTC/,
    );
});

test('depois da data o aviso diz que a negociação acabou', () => {
    assert.match(
        avisoFimNegociacao('2026-10-20', true) ?? '',
        /encerrada em 20\/10\/2026/,
    );
});
