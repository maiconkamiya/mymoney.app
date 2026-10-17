<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMicroOperationsRequest;
use App\Models\AuditLog;
use App\Models\Portfolio;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Settings of the "Micro operações" mode. Owner only.
 */
class MicroOperationsController extends Controller
{
    public function update(UpdateMicroOperationsRequest $request, Portfolio $portfolio): RedirectResponse
    {
        $data = $request->validated();

        $portfolio->update([
            'micro_enabled' => (bool) $data['micro_enabled'],
            'micro_order_value' => $data['micro_order_value'],
            'micro_net_margin_pct' => $data['micro_net_margin_pct'],
            'micro_daily_target' => $data['micro_daily_target'] ?? null,
            'micro_daily_loss_limit' => $data['micro_daily_loss_limit'] ?? null,
        ]);

        AuditLog::record('user:'.$request->user()->id, 'portfolio.micro_settings', $portfolio, [
            ...$data,
            'live' => $portfolio->isLive(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $portfolio->micro_enabled
            ? 'Micro operações ligadas: o agente opera nos pares mais voláteis dentro dos seus valores.'
            : 'Micro operações salvas (desligadas). Ciclos abertos seguem até o alvo, o stop ou o tempo limite.']);

        return back();
    }
}
