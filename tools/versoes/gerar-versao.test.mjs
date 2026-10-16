import { test } from 'node:test';
import assert from 'node:assert/strict';

import { identificarCommit, montarVersaoJson, SEM_COMMIT } from './gerar-versao.mjs';

test('commit limpo vira o hash curto', () => {
  assert.equal(identificarCommit({ hash: 'ab07d43', suja: false }), 'ab07d43');
});

test('árvore suja vira <hash>-modificado', () => {
  assert.equal(identificarCommit({ hash: 'ab07d43', suja: true }), 'ab07d43-modificado');
});

test('sem git (hash nulo ou vazio) vira sem-commit, mesmo "suja"', () => {
  assert.equal(identificarCommit({ hash: null, suja: false }), SEM_COMMIT);
  assert.equal(identificarCommit({ hash: '', suja: true }), 'sem-commit');
});

test('o conteúdo do versao.json tem versao, commit e gerado_em em ISO', () => {
  const conteudo = montarVersaoJson({
    versao: '1.0.0',
    commit: 'ab07d43',
    agora: new Date('2026-09-30T12:00:00.000Z'),
  });

  assert.deepEqual(conteudo, { versao: '1.0.0', commit: 'ab07d43', gerado_em: '2026-09-30T12:00:00.000Z' });
});
