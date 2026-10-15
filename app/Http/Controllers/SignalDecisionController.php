<?php

namespace App\Http\Controllers;

use App\Enums\SignalStage;
use App\Models\AuditLog;
use App\Models\Signal;
use App\Trading\OrderExecutor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Manual approval, the default for every portfolio: a person confirms or
 * discards each signal the agents approved.
 */
class SignalDecisionController extends Controller
{
    public function confirm(Request $request, Signal $signal, OrderExecutor $executor): RedirectResponse
    {
        $this->authorizeSignal($request, $signal);

        $actor = 'user:'.$request->user()->id;
        $signal->update(['decided_by' => $actor]);
        $signal->moveTo(SignalStage::Confirmed);
        $order = $executor->execute($signal, $actor);

        Inertia::flash('toast', $order?->filled_at
            ? ['type' => 'success', 'message' => 'Ordem executada.']
            : ['type' => 'error', 'message' => $signal->fresh()->decision_reason ?? 'Ordem não executada.']);

        return back();
    }

    public function reject(Request $request, Signal $signal): RedirectResponse
    {
        $this->authorizeSignal($request, $signal);

        $actor = 'user:'.$request->user()->id;
        $signal->update(['decided_by' => $actor]);
        $signal->moveTo(SignalStage::Rejected, 'Descartado manualmente.');
        AuditLog::record($actor, 'signal.discarded', $signal);

        return back();
    }

    private function authorizeSignal(Request $request, Signal $signal): void
    {
        abort_unless($signal->portfolio->user_id === $request->user()->id, 403);
        abort_unless($signal->stage === SignalStage::Approved, 422, 'Só sinais aprovados pelo consenso podem ser decididos.');

        if ($signal->isExpired()) {
            $signal->expire();
            abort(422, 'Sinal expirado: o preço, o stop e o alvo já não valem.');
        }
    }
}
