import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    analisarSemver,
    avaliarVersao,
    changelogTemSecao,
    compararSemver,
    ehArquivoDeCodigo,
    extrairSecaoChangelog,
} from './nucleo.mjs';

/** Fábrica de leitores (caminho -> conteúdo) para `lerArquivoAtual`/`lerArquivoMain`. */
function leitor(mapa) {
    return (caminho) => (Object.hasOwn(mapa, caminho) ? mapa[caminho] : null);
}

const pacote = (versao) =>
    JSON.stringify(
        versao === null ? { name: 'x' } : { name: 'x', version: versao },
    );
const changelog = (...versoes) =>
    `# Changelog\n\n${versoes.map((v) => `## [${v}] – 2026-09-30\n\n### Adicionado\n\n- algo\n`).join('\n')}`;

const CODIGO = ['app/Http/Controllers/PortfolioController.php'];

test('linha de base: a main não tem "version" no package.json nem CHANGELOG, passa com nota', () => {
    const atual = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });
    const main = leitor({ 'package.json': pacote(null) });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'linha_base');
    assert.match(resultado.mensagem, /Linha de base/);
});

test('linha de base: a main não tem nem o package.json, passa', () => {
    const atual = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    assert.equal(avaliarVersao(CODIGO, atual, leitor({})).estado, 'linha_base');
});

test('linha de base: a main tem a versão mas não o CHANGELOG, passa com nota', () => {
    const atual = leitor({
        'package.json': pacote('1.0.1'),
        'CHANGELOG.md': changelog('1.0.1'),
    });
    const main = leitor({ 'package.json': pacote('1.0.0') });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'linha_base');
    assert.match(resultado.mensagem, /CHANGELOG\.md/);
});

test('mudou código sem subir a versão: falha e diz a versão atual, a da main e o que fazer', () => {
    const arquivos = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, arquivos, arquivos);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /igual à da main/);
    assert.match(resultado.mensagem, /1\.0\.0/);
    assert.match(
        resultado.mensagem,
        /patch; funcionalidade: minor; quebra de contrato: major/,
    );
});

test('versão menor que a da main: falha', () => {
    const atual = leitor({
        'package.json': pacote('1.0.9'),
        'CHANGELOG.md': changelog('1.0.9'),
    });
    const main = leitor({
        'package.json': pacote('1.1.0'),
        'CHANGELOG.md': changelog('1.1.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /menor que a da main/);
});

test('subiu a versão mas o CHANGELOG não tem a seção: falha', () => {
    const atual = leitor({
        'package.json': pacote('1.1.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });
    const main = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /não tem a seção "## \[1\.1\.0\]"/);
});

test('subiu a versão e o CHANGELOG não existe na árvore atual: falha', () => {
    const atual = leitor({ 'package.json': pacote('1.1.0') });
    const main = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /CHANGELOG\.md não existe/);
});

test('subiu a versão e registrou no CHANGELOG: passa', () => {
    const atual = leitor({
        'package.json': pacote('1.1.0'),
        'CHANGELOG.md': changelog('1.1.0', '1.0.0'),
    });
    const main = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'ok');
});

test('só teste, documentação, .github e ferramentas: passa mesmo com a versão igual à da main', () => {
    const arquivos = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });
    const alterados = [
        'tests/Feature/ClienteTest.php',
        'docs/superpowers/specs/x.md',
        'README.md',
        'CLAUDE.md',
        'CHANGELOG.md',
        '.github/workflows/tests.yml',
        'tools/versoes/cli.mjs',
        'resources/js/lib/whatsappMarkdown.test.ts',
        'public/build/manifest.json',
        'public/build/versao.json',
    ];

    const resultado = avaliarVersao(alterados, arquivos, arquivos);

    assert.equal(resultado.estado, 'sem_mudanca');
});

test('classifica os caminhos de código do controle de release', () => {
    for (const caminho of [
        'app/Models/User.php',
        'bootstrap/app.php',
        'config/app.php',
        'database/migrations/2026_10_02_100000_x.php',
        'lang/pt_BR/x.php',
        'services/forecast/main.py',
        'resources/js/pages/Dashboard.vue',
        'resources/views/app.blade.php',
        'routes/web.php',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
    ]) {
        assert.equal(ehArquivoDeCodigo(caminho), true, caminho);
    }

    for (const caminho of [
        'tests/TestCase.php',
        'docs/a.md',
        'database/seeders/README.md',
        'resources/js/lib/x.test.ts',
        '.github/pull_request_template.md',
        'public/build/assets/app.js',
        'tools/versoes/cli.mjs',
        'pint.json',
        'CHANGELOG.md',
    ]) {
        assert.equal(ehArquivoDeCodigo(caminho), false, caminho);
    }
});

test('package.json removido da árvore atual: falha', () => {
    const atual = leitor({ 'CHANGELOG.md': changelog('1.0.0') });
    const main = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /não existe na árvore atual/);
});

test('package.json atual sem "version" quando a main tem: falha', () => {
    const atual = leitor({
        'package.json': pacote(null),
        'CHANGELOG.md': changelog('1.0.0'),
    });
    const main = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /não tem o campo "version"/);
});

test('versão atual fora do formato SemVer: falha', () => {
    const atual = leitor({
        'package.json': pacote('1.1'),
        'CHANGELOG.md': changelog('1.1'),
    });
    const main = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /não é SemVer válida/);
});

test('versão da main fora do formato SemVer: falha apontando a main', () => {
    const atual = leitor({
        'package.json': pacote('1.1.0'),
        'CHANGELOG.md': changelog('1.1.0'),
    });
    const main = leitor({
        'package.json': pacote('abc'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    const resultado = avaliarVersao(CODIGO, atual, main);

    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /versão da main "abc"/);
});

test('JSON malformado no package.json atual: falha sem lançar', () => {
    const atual = leitor({
        'package.json': '{ isto não é json',
        'CHANGELOG.md': changelog('1.0.0'),
    });
    const main = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });

    assert.doesNotThrow(() => avaliarVersao(CODIGO, atual, main));
    const resultado = avaliarVersao(CODIGO, atual, main);
    assert.equal(resultado.estado, 'falhou');
    assert.match(resultado.mensagem, /não é um JSON válido/);
});

test('pré-lançamento: 1.1.0-rc.1 > 1.0.0, mas 1.1.0-rc.1 < 1.1.0', () => {
    const main100 = leitor({
        'package.json': pacote('1.0.0'),
        'CHANGELOG.md': changelog('1.0.0'),
    });
    const rc = leitor({
        'package.json': pacote('1.1.0-rc.1'),
        'CHANGELOG.md': changelog('1.1.0-rc.1'),
    });
    assert.equal(avaliarVersao(CODIGO, rc, main100).estado, 'ok');

    const main110 = leitor({
        'package.json': pacote('1.1.0'),
        'CHANGELOG.md': changelog('1.1.0'),
    });
    assert.equal(avaliarVersao(CODIGO, rc, main110).estado, 'falhou');

    // E a versão final depois do rc passa.
    const final = leitor({
        'package.json': pacote('1.1.0'),
        'CHANGELOG.md': changelog('1.1.0'),
    });
    const mainRc = leitor({
        'package.json': pacote('1.1.0-rc.1'),
        'CHANGELOG.md': changelog('1.1.0-rc.1'),
    });
    assert.equal(avaliarVersao(CODIGO, final, mainRc).estado, 'ok');
});

test('compararSemver segue a precedência do SemVer 2.0', () => {
    const ordem = [
        '1.0.0-alpha',
        '1.0.0-alpha.1',
        '1.0.0-alpha.beta',
        '1.0.0-beta',
        '1.0.0-beta.2',
        '1.0.0-beta.11',
        '1.0.0-rc.1',
        '1.0.0',
    ];

    for (let i = 0; i < ordem.length - 1; i++) {
        assert.ok(
            compararSemver(
                analisarSemver(ordem[i]),
                analisarSemver(ordem[i + 1]),
            ) < 0,
            `${ordem[i]} < ${ordem[i + 1]}`,
        );
    }

    assert.equal(
        compararSemver(
            analisarSemver('1.0.0+abc'),
            analisarSemver('1.0.0+xyz'),
        ),
        0,
    );
    assert.ok(
        compararSemver(analisarSemver('1.10.0'), analisarSemver('1.9.0')) > 0,
        'comparação numérica, não de texto',
    );
});

test('analisarSemver recusa o que não é SemVer', () => {
    for (const invalida of [
        '1.0',
        '01.0.0',
        'v1.0.0',
        '1.0.0-',
        '',
        'abc',
        null,
        10,
    ]) {
        assert.equal(analisarSemver(invalida), null, String(invalida));
    }
});

test('changelogTemSecao exige o título "## [X.Y.Z]" e não confunde 1.0.0 com 1.0.01', () => {
    assert.equal(changelogTemSecao('## [1.0.0] – 2026-09-30\n', '1.0.0'), true);
    assert.equal(changelogTemSecao('## [1.0.0]\n', '1.0'), false);
    assert.equal(changelogTemSecao('## [1x0x0]\n', '1.0.0'), false);
    assert.equal(changelogTemSecao('texto [1.0.0] solto\n', '1.0.0'), false);
});

test('extrairSecaoChangelog devolve só o corpo da seção pedida', () => {
    const texto =
        '# Changelog\n\n## [1.1.0] – 2026-10-02\n\n### Adicionado\n\n- novo\n\n## [1.0.0] – 2026-09-30\n\n- antigo\n\n[1.1.0]: https://x\n';

    assert.equal(
        extrairSecaoChangelog(texto, '1.1.0'),
        '### Adicionado\n\n- novo',
    );
    assert.equal(extrairSecaoChangelog(texto, '1.0.0'), '- antigo');
    assert.equal(extrairSecaoChangelog(texto, '2.0.0'), null);
});
