<?php

namespace Tests;

use App\Support\VersaoDoSistema;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // O `composer ci` roda os testes antes do build, então não há manifest do Vite.
        $this->withoutVite();

        // Um `public/build/versao.json` de um build anterior guarda a versão antiga;
        // os testes leem só o `package.json`, que é a fonte da versão.
        $this->app->scoped(VersaoDoSistema::class, fn () => new VersaoDoSistema(
            caminhoVersaoJson: storage_path('framework/testing/sem-versao.json'),
        ));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
