<?php

namespace Tests\Unit;

use App\Support\ChangelogDoSistema;
use PHPUnit\Framework\TestCase;

class ChangelogDoSistemaTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
# Changelog

Resumo geral que não pertence a nenhuma versão.

## [Não lançado]

### Adicionado

- Ainda não saiu.

## [1.1.0] - 2026-10-20

Texto de abertura da versão.

### Adicionado

- **Painel novo** com
  duas linhas.
- Segundo item.

### Corrigido

- Um erro de cálculo.

## [1.0.0] - 2026-10-16

### Adicionado

- Primeira versão.

[1.0.0]: https://example.test/v1.0.0
MD;

    public function test_le_so_versoes_numeradas_da_mais_recente_para_a_mais_antiga()
    {
        $versoes = (new ChangelogDoSistema('/x'))->analisar(self::CHANGELOG);

        $this->assertSame(['1.1.0', '1.0.0'], array_column($versoes, 'versao'));
        $this->assertSame('2026-10-20', $versoes[0]['data']);
        $this->assertSame('Texto de abertura da versão.', $versoes[0]['resumo']);
        $this->assertNull($versoes[1]['resumo']);
    }

    public function test_agrupa_itens_por_secao_e_junta_linhas_de_continuacao()
    {
        $secoes = (new ChangelogDoSistema('/x'))->analisar(self::CHANGELOG)[0]['secoes'];

        $this->assertSame(['Adicionado', 'Corrigido'], array_column($secoes, 'titulo'));
        $this->assertSame(['**Painel novo** com duas linhas.', 'Segundo item.'], $secoes[0]['itens']);
        $this->assertSame(['Um erro de cálculo.'], $secoes[1]['itens']);
    }

    public function test_ultimas_versoes_respeita_o_limite_e_arquivo_ausente_devolve_vazio()
    {
        $caminho = tempnam(sys_get_temp_dir(), 'changelog');
        file_put_contents($caminho, self::CHANGELOG);

        $this->assertCount(1, (new ChangelogDoSistema($caminho))->ultimasVersoes(1));
        $this->assertSame([], (new ChangelogDoSistema('/nao/existe/CHANGELOG.md'))->ultimasVersoes());
    }

    public function test_o_changelog_real_do_repositorio_tem_a_secao_da_versao_do_package_json()
    {
        $versao = json_decode((string) file_get_contents(__DIR__.'/../../package.json'), true)['version'];
        $versoes = (new ChangelogDoSistema(__DIR__.'/../../CHANGELOG.md'))->versoes();

        $this->assertContains($versao, array_column($versoes, 'versao'));
    }
}
