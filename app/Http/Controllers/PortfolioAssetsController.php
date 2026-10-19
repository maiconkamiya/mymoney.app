<?php

namespace App\Http\Controllers;

use App\Events\PortfolioChanged;
use App\Models\Portfolio;
use App\Trading\PortfolioChangeBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PortfolioAssetsController extends Controller
{
    /**
     * Ativos que a carteira acompanha. Lista vazia = todos os ativos ativos do mercado.
     */
    public function update(Request $request, Portfolio $portfolio): RedirectResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'asset_ids' => ['present', 'array', 'max:200'],
            'asset_ids.*' => ['integer', 'distinct', Rule::exists('assets', 'id')
                ->where('market', $portfolio->market->value)
                ->where('active', true)
                ->where('is_benchmark', false)],
        ]);

        if ($portfolio->explorer_follow) {
            throw ValidationException::withMessages(['asset_ids' => 'A carteira segue o explorador: desligue "Seguir o explorador" para escolher os ativos à mão.']);
        }

        $portfolio->assets()->sync($data['asset_ids']);
        // O pivô não dispara evento de modelo; outras abas da mesma carteira também atualizam.
        PortfolioChangeBroadcaster::notify($portfolio->id, [PortfolioChanged::SCOPE_ASSETS, PortfolioChanged::SCOPE_MICRO]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['asset_ids'] === []
            ? 'A carteira acompanha todos os ativos cadastrados do mercado.'
            : 'Ativos acompanhados atualizados.']);

        return back();
    }
}
