<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Traz para a suíte os testes Node da checagem de subida de versão (`tools/versoes`) e do texto
 * das Novidades. A regra é uma função pura, rodada pelo runner embutido do Node.
 */
class VersoesJsTest extends TestCase
{
    public function test_roda_a_suite_da_checagem_de_versao_e_do_texto_das_novidades()
    {
        $versao = Process::run('node --version');

        // `node --test` com glob e strip de tipos precisam do Node 22.18+.
        if ($versao->failed() || version_compare(ltrim(trim($versao->output()), 'v'), '22.18.0', '<')) {
            $this->markTestSkipped('Node 22.18+ não está disponível neste ambiente.');
        }

        foreach ([
            'node --test "tools/versoes/*.test.mjs"',
            'node --experimental-strip-types --test resources/js/lib/novidadesTexto.test.ts',
        ] as $comando) {
            $resultado = Process::path(base_path())->timeout(120)->run($comando);

            $this->assertTrue($resultado->successful(), $resultado->output().$resultado->errorOutput());
        }
    }

    public function test_a_versao_do_package_json_e_semver_e_tem_secao_no_changelog()
    {
        $versao = json_decode((string) file_get_contents(base_path('package.json')), true)['version'] ?? null;

        $this->assertIsString($versao);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $versao);
        $this->assertStringContainsString("## [{$versao}]", (string) file_get_contents(base_path('CHANGELOG.md')));
    }
}
