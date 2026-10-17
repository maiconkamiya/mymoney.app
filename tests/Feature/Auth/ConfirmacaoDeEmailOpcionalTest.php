<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmacaoDeEmailOpcionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_com_a_confirmacao_ligada_quem_nao_confirmou_nao_entra_no_painel(): void
    {
        config(['auth.email_verification' => true]);
        $usuario = User::factory()->unverified()->create();

        $this->actingAs($usuario)->get(route('dashboard'))->assertRedirect();
        $this->assertFalse($usuario->hasVerifiedEmail());
    }

    public function test_com_a_confirmacao_desligada_quem_nao_confirmou_entra_no_painel(): void
    {
        config(['auth.email_verification' => false]);
        $usuario = User::factory()->unverified()->create();

        $this->assertTrue($usuario->hasVerifiedEmail());
        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
    }

    public function test_o_padrao_desliga_so_em_ambiente_local(): void
    {
        $caminho = config_path('auth.php');
        $this->assertStringContainsString("env('APP_ENV') !== 'local'", (string) file_get_contents($caminho));
    }
}
