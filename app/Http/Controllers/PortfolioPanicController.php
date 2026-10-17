<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Trading\Live\PanicStop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Parar tudo" on one portfolio, and the release that follows. Owner only.
 */
class PortfolioPanicController extends Controller
{
    public function store(Request $request, Portfolio $portfolio, PanicStop $panic): RedirectResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        $result = $panic->stop($portfolio, 'user:'.$request->user()->id, 'Botão "Parar tudo" na carteira.');

        Inertia::flash('toast', $result['failed'] === []
            ? ['type' => 'success', 'message' => 'Tudo parado: execução automática desligada, novas ordens bloqueadas e ordens abertas canceladas ('.count($result['cancelled']).').']
            : ['type' => 'error', 'message' => 'Novas ordens bloqueadas, mas '.count($result['failed']).' cancelamento(s) na Binance falharam. Confira as ordens abertas na corretora.']);

        return back();
    }

    public function destroy(Request $request, Portfolio $portfolio, PanicStop $panic): RedirectResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        $panic->resume($portfolio, 'user:'.$request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ordens liberadas. A execução automática continua desligada até você ligar de novo.']);

        return back();
    }
}
