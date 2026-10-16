<?php

namespace Tests\Feature;

use App\Http\Middleware\AdicionarCabecalhoDeVersao;
use App\Models\User;
use App\Support\VersaoDoSistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NovidadesTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_vai_para_o_login()
    {
        $this->get(route('novidades'))->assertRedirect(route('login'));
        $this->post(route('novidades.vista'))->assertRedirect(route('login'));
    }

    public function test_a_pagina_lista_as_versoes_do_changelog_e_marca_a_versao_como_vista()
    {
        $versaoAtual = app(VersaoDoSistema::class)->versao();
        $user = User::factory()->create(['ultima_versao_vista' => '0.0.1']);

        $this->actingAs($user)->get(route('novidades'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Novidades')
                ->where('versoes.0.versao', $versaoAtual)
                ->has('versoes.0.secoes'));

        $this->assertSame($versaoAtual, $user->fresh()->ultima_versao_vista);
    }

    public function test_aviso_so_aparece_para_quem_ainda_nao_viu_a_versao_corrente()
    {
        $user = User::factory()->create(['ultima_versao_vista' => '0.0.1']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sistema.aviso_novidade', true)
                ->where('sistema.versao', app(VersaoDoSistema::class)->versao()));

        $this->actingAs($user)->post(route('novidades.vista'))->assertNoContent();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('sistema.aviso_novidade', false));
    }

    public function test_quem_acaba_de_se_cadastrar_nao_ve_o_aviso_da_versao_que_ja_estava_no_ar()
    {
        $user = User::factory()->create();

        $this->assertSame(app(VersaoDoSistema::class)->versao(), $user->ultima_versao_vista);
        $this->assertFalse($user->temNovidadeNaoVista(app(VersaoDoSistema::class)));
    }

    public function test_as_respostas_web_levam_o_cabecalho_com_a_versao()
    {
        $esperado = app(VersaoDoSistema::class)->rotuloParaCabecalho();

        $this->get(route('home'))->assertHeader(AdicionarCabecalhoDeVersao::CABECALHO, $esperado);
    }
}
