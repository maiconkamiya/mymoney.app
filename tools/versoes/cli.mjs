#!/usr/bin/env node
/**
 * CLI da checagem `versoes`. Toda a decisão vive em `nucleo.mjs` (pura, testada por
 * `node --test tools/versoes/`); este arquivo só faz o I/O que o núcleo recusa fazer:
 * `git fetch`/`git diff`/`git show` e leitura de arquivo. Nada aqui passa por pipe: um
 * `| tail`/`| grep` mascararia o código de saída real do git.
 *
 * ## Códigos de saída
 * - `0`: passou (ok, linha de base, ou sem mudança de código).
 * - `1`: falhou a regra de subida.
 * - `2`: não foi possível confiar no diff (sem `origin/main`, sem merge-base). Nunca é um "passa"
 *   silencioso: a árvore NÃO foi verificada.
 *
 * Uso: `node tools/versoes/cli.mjs` (ou `npm run versoes`).
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { avaliarVersao } from './nucleo.mjs';

const RAIZ = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function git(args) {
  return execFileSync('git', args, { cwd: RAIZ, encoding: 'utf8' });
}

/** Devolve o erro em vez de lançar: "não consegui confiar no diff" é uma condição, não um bug. */
function tentarGit(args) {
  try {
    return { saida: git(args), erro: null };
  } catch (erro) {
    return { saida: null, erro };
  }
}

function pular(motivo) {
  console.error('versoes: PULADO');
  console.error(`  ${motivo}`);
  console.error('\nA árvore NÃO está verificada quanto à checagem de versão.');
  process.exit(2);
}

// `git fetch origin main` ANTES do merge-base: sem isso, `origin/main` local pode estar
// desatualizado (ou nem existir, num clone raso) e o merge-base mentiria sobre o que mudou.
const fetch = tentarGit(['fetch', 'origin', 'main']);
if (fetch.erro !== null) {
  pular(`\`git fetch origin main\` falhou: sem uma origin/main confiável não dá para calcular o diff. Detalhe: ${fetch.erro.message.trim()}`);
}

const mergeBase = tentarGit(['merge-base', 'origin/main', 'HEAD']);
if (mergeBase.erro !== null) {
  pular(`\`git merge-base origin/main HEAD\` falhou (histórico sem ancestral comum?). Detalhe: ${mergeBase.erro.message.trim()}`);
}
const baseSha = mergeBase.saida.trim();

const diff = tentarGit(['diff', '--name-only', `${baseSha}..HEAD`]);
if (diff.erro !== null) {
  pular(`\`git diff --name-only ${baseSha}..HEAD\` falhou. Detalhe: ${diff.erro.message.trim()}`);
}
const arquivosAlterados = diff.saida.split('\n').map((linha) => linha.trim()).filter(Boolean);

function lerArquivoAtual(caminhoRelativo) {
  const caminhoAbsoluto = join(RAIZ, caminhoRelativo);
  if (!existsSync(caminhoAbsoluto)) {
    return null;
  }

  return readFileSync(caminhoAbsoluto, 'utf8');
}

function lerArquivoMain(caminhoRelativo) {
  try {
    // stderr ignorado DE PROPÓSITO só aqui: "o arquivo não existe naquela revisão" é o caminho
    // ESPERADO na linha de base, e o `fatal: path ... does not exist` do git poluiria a saída
    // de toda checagem que introduz uma fonte nova.
    return execFileSync('git', ['show', `${baseSha}:${caminhoRelativo}`], {
      cwd: RAIZ,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    });
  } catch {
    return null;
  }
}

console.log('Checagem `versoes` (tools/versoes/cli.mjs)\n');
console.log(`  merge-base com origin/main: ${baseSha}`);
console.log(`  arquivos alterados: ${arquivosAlterados.length}\n`);

const resultado = avaliarVersao(arquivosAlterados, lerArquivoAtual, lerArquivoMain);
const MARCA = { ok: '✓', linha_base: '○', sem_mudanca: '·', falhou: '✗' };

console.log(`  ${MARCA[resultado.estado]} ${resultado.mensagem}\n`);

if (resultado.estado === 'falhou') {
  console.error('Checagem `versoes` falhou. Corrija antes de abrir/atualizar o PR.');
  process.exit(1);
}

console.log('Checagem `versoes`: tudo certo.');
process.exit(0);
