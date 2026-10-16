<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdiomaTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_idioma_padrao_e_portugues(): void
    {
        $this->assertSame('pt_BR', app()->getLocale());
    }

    public function test_login_com_senha_errada_responde_em_portugues(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'senha-errada',
        ])->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
    }

    public function test_validacao_usa_o_nome_do_campo_em_portugues(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Pessoa',
            'email' => '',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors(['email' => 'O campo e-mail é obrigatório.']);
    }
}
