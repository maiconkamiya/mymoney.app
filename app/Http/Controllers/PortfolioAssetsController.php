<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $portfolio->assets()->sync($data['asset_ids']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['asset_ids'] === []
            ? 'A carteira acompanha todos os ativos cadastrados do mercado.'
            : 'Ativos acompanhados atualizados.']);

        return back();
    }
}
