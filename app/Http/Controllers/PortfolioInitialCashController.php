<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInitialCashRequest;
use App\Models\AuditLog;
use App\Models\Portfolio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PortfolioInitialCashController extends Controller
{
    /**
     * O caixa e o histórico de patrimônio andam pela diferença (novo menos antigo), como se
     * o capital tivesse sido esse desde o início; posições, ordens e sinais não são tocados.
     */
    public function __invoke(UpdateInitialCashRequest $request, Portfolio $portfolio): RedirectResponse
    {
        $old = $portfolio->initial_cash;
        $new = round((float) $request->validated('initial_cash'), 2);
        $delta = round($new - $old, 8);

        if ($delta !== 0.0) {
            DB::transaction(function () use ($request, $portfolio, $old, $new, $delta): void {
                $portfolio->update(['initial_cash' => $new, 'cash' => $portfolio->cash + $delta]);
                $portfolio->snapshots()->increment('equity', $delta);
                $portfolio->snapshots()->increment('cash', $delta);

                AuditLog::record('user:'.$request->user()->id, 'portfolio.initial_cash', $portfolio, ['old' => $old, 'new' => $new, 'cash_delta' => $delta]);
            });
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $delta === 0.0 ? 'O saldo inicial já era esse valor.' : 'Saldo inicial alterado.']);

        return back();
    }
}
