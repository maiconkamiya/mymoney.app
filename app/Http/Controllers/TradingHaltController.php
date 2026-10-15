<?php

namespace App\Http\Controllers;

use App\Trading\TradingHalt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TradingHaltController extends Controller
{
    public function store(Request $request, TradingHalt $halt): RedirectResponse
    {
        $halt->halt('user:'.$request->user()->id, 'Botão de parada geral no painel.');

        return back();
    }

    public function destroy(Request $request, TradingHalt $halt): RedirectResponse
    {
        $halt->resume('user:'.$request->user()->id);

        return back();
    }
}
