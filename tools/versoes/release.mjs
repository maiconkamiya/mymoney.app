#!/usr/bin/env node
/**
 * Confere uma tag de release contra o `package.json` e o CHANGELOG, e grava as notas da release.
 * Roda na máquina de quem publica (`npm run release -- vX.Y.Z`): o projeto não usa GitHub Actions.
 * A regra é a mesma testada em `nucleo.test.mjs`.
 *
 * Uso: `node tools/versoes/release.mjs <tag> [arquivo-de-notas]`
 *
 * - A tag tem de ser `vX.Y.Z` (SemVer, com pré-lançamento opcional) e bater com o `version` do
 *   `package.json`.
 * - O CHANGELOG tem de ter a seção `## [X.Y.Z]`; o corpo dela vira o arquivo de notas (padrão:
 *   `release-notas.md`).
 *
 * Saída `0` ok; `1` divergência (mensagem clara no stderr).
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { analisarSemver, ARQUIVO_CHANGELOG, ARQUIVO_VERSAO, extrairSecaoChangelog, extrairVersaoPackageJson } from './nucleo.mjs';

const RAIZ = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function falhar(mensagem) {
  console.error(`release: ${mensagem}`);
  process.exit(1);
}

const tag = process.argv[2] ?? '';
const arquivoNotas = process.argv[3] ?? 'release-notas.md';

if (!/^v/.test(tag)) {
  falhar(`a tag "${tag}" não começa com "v". Use vX.Y.Z (ex.: v1.0.0), criada pelo dono do projeto.`);
}

const versaoDaTag = tag.slice(1);
if (analisarSemver(versaoDaTag) === null) {
  falhar(`a tag "${tag}" não é vX.Y.Z (SemVer, com pré-lançamento -rc.1 opcional).`);
}

let versaoDoPackage;
try {
  versaoDoPackage = extrairVersaoPackageJson(readFileSync(join(RAIZ, ARQUIVO_VERSAO), 'utf8'));
} catch (erro) {
  falhar(erro.message);
}
if (versaoDoPackage === null) {
  falhar(`${ARQUIVO_VERSAO} não tem o campo "version".`);
}
if (versaoDoPackage !== versaoDaTag) {
  falhar(`a tag "${tag}" (versão ${versaoDaTag}) não bate com o "version" ${versaoDoPackage} do ${ARQUIVO_VERSAO}. Atualize um dos dois antes de marcar a tag.`);
}

const secao = extrairSecaoChangelog(readFileSync(join(RAIZ, ARQUIVO_CHANGELOG), 'utf8'), versaoDaTag);
if (secao === null || secao === '') {
  falhar(`o ${ARQUIVO_CHANGELOG} não tem a seção "## [${versaoDaTag}]" (ou ela está vazia).`);
}

writeFileSync(resolve(RAIZ, arquivoNotas), `${secao}\n`);
console.log(`release: tag ${tag} bate com o ${ARQUIVO_VERSAO}; notas gravadas em ${arquivoNotas}.`);
console.log(`\nPara publicar (feito pelo dono do projeto, depois de empurrar a tag):\n  gh release create ${tag} --title "MyMoney.App ${tag}" --notes-file ${arquivoNotas}${versaoDaTag.includes('-') ? ' --prerelease' : ''}`);
