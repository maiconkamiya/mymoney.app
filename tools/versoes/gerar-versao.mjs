#!/usr/bin/env node
/**
 * Grava `public/build/versao.json` com a identificação do build:
 * `{"versao": "1.0.0", "commit": "ab07d43", "gerado_em": "2026-09-30T12:00:00.000Z"}`.
 *
 * Roda no `npm run build` (depois do `vite build`, que esvazia `public/build`). O backend
 * (`App\Support\VersaoDoSistema`) lê o arquivo; sem ele, cai no `package.json` com `sem-commit`.
 *
 * O commit vem de `git rev-parse --short=7 HEAD`:
 * - sem git (tarball, build em contêiner sem `.git`): `MYMONEY_COMMIT` se definida, senão
 *   `sem-commit`;
 * - árvore suja (`git status --porcelain` com algo): `<hash>-modificado`, para um build com
 *   alteração local nunca parecer um commit limpo.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const SEM_COMMIT = 'sem-commit';

/**
 * @param {{hash: string|null, suja: boolean}} git estado do git (hash curto ou null sem git)
 * @return {string}
 */
export function identificarCommit({ hash, suja }) {
    if (hash === null || hash === '') {
        return SEM_COMMIT;
    }

    return suja ? `${hash}-modificado` : hash;
}

/**
 * @return {{versao: string, commit: string, gerado_em: string}}
 */
export function montarVersaoJson({ versao, commit, agora }) {
    return { versao, commit, gerado_em: agora.toISOString() };
}

function executarGit(raiz, args) {
    try {
        return execFileSync('git', args, {
            cwd: raiz,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'ignore'],
        }).trim();
    } catch {
        return null;
    }
}

function lerEstadoDoGit(raiz) {
    const hash = executarGit(raiz, ['rev-parse', '--short=7', 'HEAD']);
    if (hash === null || hash === '') {
        const informado = (process.env.MYMONEY_COMMIT ?? '').trim();

        return {
            hash: informado === '' ? null : informado.slice(0, 7),
            suja: false,
        };
    }

    const status = executarGit(raiz, ['status', '--porcelain']);

    return { hash, suja: status !== null && status !== '' };
}

function gerar() {
    const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
    const { version } = JSON.parse(
        readFileSync(join(raiz, 'package.json'), 'utf8'),
    );

    if (typeof version !== 'string' || version === '') {
        console.error(
            'gerar-versao: o package.json não tem o campo "version".',
        );
        process.exit(1);
    }

    const conteudo = montarVersaoJson({
        versao: version,
        commit: identificarCommit(lerEstadoDoGit(raiz)),
        agora: new Date(),
    });

    const destino = join(raiz, 'public', 'build', 'versao.json');
    mkdirSync(dirname(destino), { recursive: true });
    writeFileSync(destino, `${JSON.stringify(conteudo, null, 4)}\n`);

    console.log(
        `gerar-versao: ${conteudo.versao} · ${conteudo.commit} → public/build/versao.json`,
    );
}

if (
    process.argv[1] &&
    resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
    gerar();
}
