#!/usr/bin/env node
/**
 * Lógica pura da checagem `versoes` do MyMoney.App: "todo PR que muda o app sobe a
 * versão, em SemVer, com seção no CHANGELOG.md".
 *
 * Deliberadamente sem `fs`, sem `child_process`, sem git: só recebe dados (a lista de arquivos
 * alterados e duas funções de leitura injetáveis) e devolve um veredito. É o que torna
 * `node --test tools/versoes/` possível sem um repositório git de mentira nem arquivos reais em
 * disco. Quem chama isto com git/fs de verdade é `tools/versoes/cli.mjs`.
 *
 * Desenho herdado do opusdom.app: a fonte da versão é `package.json` → `version`, o CHANGELOG é
 * `CHANGELOG.md` na raiz.
 */

export const ARQUIVO_VERSAO = 'package.json';
export const ARQUIVO_CHANGELOG = 'CHANGELOG.md';

/** Diretórios cuja mudança é código do app (o que o cliente recebe num deploy). */
const PREFIXOS_DE_CODIGO = [
    'app/',
    'bootstrap/',
    'config/',
    'database/',
    'lang/',
    'public/',
    'resources/',
    'routes/',
    'services/',
];

/** Arquivos da raiz que mudam o que roda (dependências e a própria versão). */
const ARQUIVOS_DE_CODIGO = [
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
];

/** `public/build` é saída de compilação, nunca fonte. */
const PREFIXO_BUILD = 'public/build/';

const PADRAO_TESTE_NO_CAMINHO = /(^|\/)tests?(\/|$)/i;
const PADRAO_ARQUIVO_DE_TESTE = /\.test\.(ts|mjs|js|vue)$/i;

/**
 * Decide se um caminho (relativo à raiz, separador `/`) muda código do app.
 *
 * Fora: `tests/`, `docs/`, `.github/`, qualquer `*.md`, `public/build/` e arquivos `*.test.*`
 * (os testes do Node ficam ao lado do código em `resources/js/lib` e `tools/`). Dentro: os
 * diretórios de `PREFIXOS_DE_CODIGO` e `ARQUIVOS_DE_CODIGO`. O que não está em nenhuma das duas
 * listas (ex.: `README.md`, `pint.json`, `tools/`) não exige subida.
 */
export function ehArquivoDeCodigo(caminho) {
    const c = caminho.replace(/\\/g, '/').replace(/^\.\//, '');

    if (
        /\.md$/i.test(c) ||
        c.startsWith('tests/') ||
        c.startsWith('docs/') ||
        c.startsWith('.github/')
    ) {
        return false;
    }

    if (
        c.startsWith(PREFIXO_BUILD) ||
        PADRAO_ARQUIVO_DE_TESTE.test(c) ||
        PADRAO_TESTE_NO_CAMINHO.test(c)
    ) {
        return false;
    }

    if (ARQUIVOS_DE_CODIGO.includes(c)) {
        return true;
    }

    return PREFIXOS_DE_CODIGO.some((prefixo) => c.startsWith(prefixo));
}

/**
 * SemVer 2.0: `X.Y.Z`, com pré-lançamento (`-rc.1`) e metadado de build (`+abc`) opcionais.
 * Sem zeros à esquerda nos números (item 2 da especificação).
 */
const PADRAO_SEMVER =
    /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/;

/**
 * @return {{major: number, minor: number, patch: number, preRelease: string[]}|null} null quando
 *         o texto não é uma versão SemVer válida.
 */
export function analisarSemver(versao) {
    if (typeof versao !== 'string') {
        return null;
    }

    const m = PADRAO_SEMVER.exec(versao.trim());
    if (!m) {
        return null;
    }

    return {
        major: Number(m[1]),
        minor: Number(m[2]),
        patch: Number(m[3]),
        preRelease: m[4] === undefined ? [] : m[4].split('.'),
    };
}

function ehNumerico(identificador) {
    return /^\d+$/.test(identificador);
}

/**
 * Precedência do SemVer 2.0 (item 11): compara major/minor/patch; uma versão COM pré-lançamento é
 * MENOR que a mesma sem ele (`1.0.0-rc.1 < 1.0.0`); entre pré-lançamentos, identificador a
 * identificador (numérico < alfanumérico; mais campos vence quando o prefixo é igual). O
 * metadado de build (`+abc`) não entra na comparação.
 *
 * @return {number} negativo se a < b, positivo se a > b, 0 se iguais.
 */
export function compararSemver(a, b) {
    for (const campo of ['major', 'minor', 'patch']) {
        if (a[campo] !== b[campo]) {
            return a[campo] - b[campo];
        }
    }

    if (a.preRelease.length === 0 && b.preRelease.length === 0) {
        return 0;
    }
    if (a.preRelease.length === 0) {
        return 1;
    }
    if (b.preRelease.length === 0) {
        return -1;
    }

    const tamanho = Math.min(a.preRelease.length, b.preRelease.length);
    for (let i = 0; i < tamanho; i++) {
        const x = a.preRelease[i];
        const y = b.preRelease[i];
        if (x === y) {
            continue;
        }

        const xNumerico = ehNumerico(x);
        const yNumerico = ehNumerico(y);
        if (xNumerico && yNumerico) {
            return Number(x) - Number(y);
        }
        if (xNumerico) {
            return -1;
        }
        if (yNumerico) {
            return 1;
        }

        return x < y ? -1 : 1;
    }

    return a.preRelease.length - b.preRelease.length;
}

function escaparRegExp(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** `true` se o CHANGELOG tem um título de seção `## [X.Y.Z]` (Keep a Changelog) para a versão. */
export function changelogTemSecao(conteudo, versao) {
    return new RegExp(`^##\\s*\\[${escaparRegExp(versao)}\\]`, 'm').test(
        conteudo,
    );
}

/**
 * Corpo da seção `## [versao]` do CHANGELOG (sem o título), até o próximo `## `. Usado pelo
 * release (`tools/versoes/release.mjs`) para montar as notas da GitHub Release.
 *
 * @return {string|null} null quando a versão não tem seção.
 */
export function extrairSecaoChangelog(conteudo, versao) {
    const linhas = conteudo.split(/\r?\n/);
    const padraoTitulo = new RegExp(`^##\\s*\\[${escaparRegExp(versao)}\\]`);
    const inicio = linhas.findIndex((linha) => padraoTitulo.test(linha));
    if (inicio === -1) {
        return null;
    }

    let fim = linhas.length;
    for (let i = inicio + 1; i < linhas.length; i++) {
        if (/^##\s/.test(linhas[i]) || /^\[[^\]]+\]:\s/.test(linhas[i])) {
            fim = i;
            break;
        }
    }

    return linhas
        .slice(inicio + 1, fim)
        .join('\n')
        .trim();
}

/**
 * Lê `version` de um `package.json`.
 *
 * @return {string|null} null quando o JSON é válido mas não tem `version` (a linha de base da
 *         main, antes deste mecanismo).
 * @throws {Error} quando o JSON é inválido.
 */
export function extrairVersaoPackageJson(conteudo, caminho = ARQUIVO_VERSAO) {
    let json;
    try {
        json = JSON.parse(conteudo);
    } catch (erro) {
        throw new Error(`${caminho} não é um JSON válido: ${erro.message}`);
    }

    return typeof json.version === 'string' ? json.version : null;
}

/**
 * Avalia a checagem `versoes`.
 *
 * @param {ReadonlyArray<string>} arquivosAlterados diff contra o merge-base com origin/main,
 *        caminhos relativos à raiz do repositório
 * @param {(caminho: string) => string|null} lerArquivoAtual conteúdo na árvore atual, ou null se
 *        o arquivo não existe
 * @param {(caminho: string) => string|null} lerArquivoMain conteúdo em origin/main (merge-base),
 *        ou null se o arquivo não existe lá
 * @return {{estado: 'sem_mudanca'|'linha_base'|'ok'|'falhou', mensagem: string}}
 */
export function avaliarVersao(
    arquivosAlterados,
    lerArquivoAtual,
    lerArquivoMain,
) {
    if (!arquivosAlterados.some(ehArquivoDeCodigo)) {
        return {
            estado: 'sem_mudanca',
            mensagem:
                'Nenhum arquivo de código do app mudou (só teste, documentação, .github ou ferramentas): subida de versão não exigida.',
        };
    }

    const packageMain = lerArquivoMain(ARQUIVO_VERSAO);
    const changelogMain = lerArquivoMain(ARQUIVO_CHANGELOG);

    let versaoMain = null;
    if (packageMain !== null) {
        try {
            versaoMain = extrairVersaoPackageJson(packageMain);
        } catch (erro) {
            return { estado: 'falhou', mensagem: `${erro.message} (na main).` };
        }
    }

    // Linha de base: a main ainda não tem a versão no package.json OU não tem o CHANGELOG. É a
    // introdução do mecanismo; cobrar subida contra uma main que não tinha versão não faz sentido.
    // Vale só para o PR que introduz (depois dele a main passa a ter os dois).
    if (versaoMain === null || changelogMain === null) {
        const faltando =
            versaoMain === null && changelogMain === null
                ? `o campo "version" do ${ARQUIVO_VERSAO} e o ${ARQUIVO_CHANGELOG}`
                : versaoMain === null
                  ? `o campo "version" do ${ARQUIVO_VERSAO}`
                  : `o ${ARQUIVO_CHANGELOG}`;

        return {
            estado: 'linha_base',
            mensagem: `Linha de base: a main ainda não tem ${faltando}. Passa sem exigir subida (vale só para este PR; o próximo PR que mudar o app já sobe a versão).`,
        };
    }

    const packageAtual = lerArquivoAtual(ARQUIVO_VERSAO);
    if (packageAtual === null) {
        return {
            estado: 'falhou',
            mensagem: `${ARQUIVO_VERSAO} não existe na árvore atual (foi removido?). A main tem a versão ${versaoMain}; esta branch precisa continuar tendo.`,
        };
    }

    let versaoAtual;
    try {
        versaoAtual = extrairVersaoPackageJson(packageAtual);
    } catch (erro) {
        return { estado: 'falhou', mensagem: erro.message };
    }
    if (versaoAtual === null) {
        return {
            estado: 'falhou',
            mensagem: `${ARQUIVO_VERSAO} não tem o campo "version" (string). A main está na ${versaoMain}: mantenha o campo e suba a versão.`,
        };
    }

    const partesAtual = analisarSemver(versaoAtual);
    if (partesAtual === null) {
        return {
            estado: 'falhou',
            mensagem: `A versão atual "${versaoAtual}" não é SemVer válida (formato X.Y.Z, com pré-lançamento -rc.1 opcional). Corrija o "version" do ${ARQUIVO_VERSAO}.`,
        };
    }

    const partesMain = analisarSemver(versaoMain);
    if (partesMain === null) {
        return {
            estado: 'falhou',
            mensagem: `A versão da main "${versaoMain}" não é SemVer válida, então não dá para comparar. Corrija o "version" do ${ARQUIVO_VERSAO} na main antes.`,
        };
    }

    const comparacao = compararSemver(partesAtual, partesMain);
    if (comparacao <= 0) {
        const relacao =
            comparacao === 0 ? 'igual à da main' : 'menor que a da main';

        return {
            estado: 'falhou',
            mensagem: `Este PR muda código do app, mas a versão atual (${versaoAtual}) é ${relacao} (${versaoMain}). Suba o "version" do ${ARQUIVO_VERSAO} (correção: patch; funcionalidade: minor; quebra de contrato: major) e registre a mudança em uma seção "## [${versaoAtual}]" do ${ARQUIVO_CHANGELOG}, escrita para o cliente.`,
        };
    }

    const changelogAtual = lerArquivoAtual(ARQUIVO_CHANGELOG);
    if (changelogAtual === null) {
        return {
            estado: 'falhou',
            mensagem: `A versão subiu para ${versaoAtual} (main: ${versaoMain}), mas o ${ARQUIVO_CHANGELOG} não existe na árvore atual.`,
        };
    }

    if (!changelogTemSecao(changelogAtual, versaoAtual)) {
        return {
            estado: 'falhou',
            mensagem: `A versão subiu para ${versaoAtual} (main: ${versaoMain}), mas o ${ARQUIVO_CHANGELOG} não tem a seção "## [${versaoAtual}]". Adicione a seção (Keep a Changelog: Adicionado, Alterado, Corrigido, Removido), escrita para o cliente.`,
        };
    }

    return {
        estado: 'ok',
        mensagem: `Versão ${versaoAtual} > main ${versaoMain}, e o ${ARQUIVO_CHANGELOG} tem a seção [${versaoAtual}].`,
    };
}
