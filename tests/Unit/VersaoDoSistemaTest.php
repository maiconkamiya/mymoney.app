<?php

namespace Tests\Unit;

use App\Support\VersaoDoSistema;
use PHPUnit\Framework\TestCase;

class VersaoDoSistemaTest extends TestCase
{
    private function arquivo(string $conteudo, string $nome = 'versao.json'): string
    {
        $diretorio = sys_get_temp_dir().'/mymoney-versao-'.bin2hex(random_bytes(6));
        mkdir($diretorio);
        file_put_contents($diretorio.'/'.$nome, $conteudo);

        return $diretorio.'/'.$nome;
    }

    public function test_le_versao_commit_e_data_do_versao_json_do_build()
    {
        $versao = new VersaoDoSistema($this->arquivo(json_encode([
            'versao' => '1.4.0',
            'commit' => 'ab07d43',
            'gerado_em' => '2026-09-30T12:00:00.000Z',
        ])));

        $this->assertSame('1.4.0', $versao->versao());
        $this->assertSame('ab07d43', $versao->commit());
        $this->assertSame('2026-09-30T12:00:00.000Z', $versao->geradoEm());
        $this->assertSame('1.4.0 (ab07d43)', $versao->rotuloParaCabecalho());
        $this->assertSame('1.4.0 · ab07d43', $versao->rotuloParaTela());
    }

    public function test_sem_o_versao_json_usa_o_package_json_e_o_commit_sem_commit()
    {
        $versao = new VersaoDoSistema('/nao/existe/versao.json', $this->arquivo('{"version":"2.3.4"}', 'package.json'));

        $this->assertSame('2.3.4', $versao->versao());
        $this->assertSame('sem-commit', $versao->commit());
        $this->assertNull($versao->geradoEm());
    }

    public function test_o_fallback_padrao_bate_com_o_package_json_do_repositorio()
    {
        $esperada = json_decode((string) file_get_contents(__DIR__.'/../../package.json'), true)['version'];

        $this->assertSame($esperada, (new VersaoDoSistema('/nao/existe/versao.json'))->versao());
    }

    public function test_versao_json_corrompido_ou_sem_versao_cai_no_package_json()
    {
        foreach (['{ isto não é json', '{"commit":"ab07d43"}', '{"versao":"  "}'] as $conteudo) {
            $versao = new VersaoDoSistema($this->arquivo($conteudo), $this->arquivo('{"version":"1.0.0"}', 'package.json'));

            $this->assertSame('1.0.0', $versao->versao());
            $this->assertSame('sem-commit', $versao->commit());
        }
    }

    public function test_sem_nenhum_dos_dois_arquivos_a_versao_e_zero()
    {
        $this->assertSame('0.0.0', (new VersaoDoSistema('/x/versao.json', '/x/package.json'))->versao());
    }

    public function test_compara_versoes_e_quem_nunca_viu_nada_ve_a_atual_como_nova()
    {
        $this->assertTrue(VersaoDoSistema::ehPosterior('1.1.0', '1.0.0'));
        $this->assertTrue(VersaoDoSistema::ehPosterior('1.10.0', '1.9.0'));
        $this->assertFalse(VersaoDoSistema::ehPosterior('1.0.0', '1.0.0'));
        $this->assertFalse(VersaoDoSistema::ehPosterior('1.0.0', '1.1.0'));
        $this->assertFalse(VersaoDoSistema::ehPosterior('1.1.0-rc.1', '1.1.0'));
        $this->assertTrue(VersaoDoSistema::ehPosterior('1.0.0', null));
        $this->assertTrue(VersaoDoSistema::ehPosterior('1.0.0', ''));
    }
}
