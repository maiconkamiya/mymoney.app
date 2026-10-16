<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ChangelogDoSistema;
use App\Support\VersaoDoSistema;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página "Novidades" e o registro do aviso "Nova versão X".
 *
 * Qualquer usuário autenticado vê o que mudou. O aviso é por usuário (`users.ultima_versao_vista`).
 */
class NovidadesController extends Controller
{
    /** Quantas versões do CHANGELOG a página mostra. */
    private const ULTIMAS_VERSOES = 10;

    public function show(Request $request, ChangelogDoSistema $changelog, VersaoDoSistema $versao): Response
    {
        $resposta = Inertia::render('Novidades', [
            'versoes' => $changelog->ultimasVersoes(self::ULTIMAS_VERSOES),
        ]);

        // Abrir a página é ver a novidade: o aviso não volta.
        $usuario = $request->user();
        if ($usuario instanceof User) {
            $usuario->marcarVersaoComoVista($versao);
        }

        return $resposta;
    }

    /** Registra que a pessoa viu (ou dispensou) o aviso da versão corrente. */
    public function marcarComoVista(Request $request, VersaoDoSistema $versao): HttpResponse
    {
        $usuario = $request->user();
        if ($usuario instanceof User) {
            $usuario->marcarVersaoComoVista($versao);
        }

        return response()->noContent();
    }
}
