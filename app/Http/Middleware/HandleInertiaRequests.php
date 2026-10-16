<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\VersaoDoSistema;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sistema' => fn () => $this->sistema($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Versão e commit do build, no rodapé do menu. `aviso_novidade` é o aviso "Nova versão X":
     * só para quem está logado e ainda não viu a versão corrente.
     *
     * @return array{versao: string, commit: string, aviso_novidade: bool}
     */
    private function sistema(Request $request): array
    {
        $versao = app(VersaoDoSistema::class);
        $usuario = $request->user();

        return [
            'versao' => $versao->versao(),
            'commit' => $versao->commit(),
            'aviso_novidade' => $usuario instanceof User && $usuario->temNovidadeNaoVista($versao),
        ];
    }
}
