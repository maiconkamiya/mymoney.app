<?php

namespace App\Http\Controllers;

use App\Events\PortfolioChanged;
use App\Http\Requests\StoreDepositRequest;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Trading\PortfolioChangeBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PortfolioDepositController extends Controller
{
    /**
     * Novo aporte na carteira simulada: soma ao caixa e ao total investido e fica registrado com a
     * data, sem reescrever o histórico. A guarda e o relatório somam aos pontos anteriores os aportes
     * feitos depois deles, então o aporte não conta como ganho nem como perda. A carteira real
     * recebe aportes pela Binance.
     */
    public function store(StoreDepositRequest $request, Portfolio $portfolio): RedirectResponse
    {
        abort_if($portfolio->isLive(), 403, 'Aporte na carteira real é feito na Binance.');

        $amount = round((float) $request->validated('amount'), 2);

        DB::transaction(function () use ($request, $portfolio, $amount): void {
            // Incremento atômico: o motor das micro mexe no caixa ao mesmo tempo.
            Portfolio::query()->whereKey($portfolio->id)->incrementEach(['cash' => $amount, 'initial_cash' => $amount]);
            $deposit = $portfolio->deposits()->create(['amount' => $amount, 'deposited_at' => now()]);

            AuditLog::record('user:'.$request->user()->id, 'portfolio.deposit', $portfolio, ['deposit_id' => $deposit->id, 'amount' => $amount]);
        });

        PortfolioChangeBroadcaster::notify($portfolio->id, [PortfolioChanged::SCOPE_BALANCE, PortfolioChanged::SCOPE_MICRO]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aporte registrado.']);

        return back();
    }
}
