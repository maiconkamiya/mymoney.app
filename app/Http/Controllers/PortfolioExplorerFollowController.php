<?php

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Events\PortfolioChanged;
use App\Explorer\PortfolioFollower;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Trading\PortfolioChangeBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PortfolioExplorerFollowController extends Controller
{
    /**
     * Liga ou desliga o "Seguir o explorador" e guarda o limite e a permanência.
     * Ligando, aplica na hora a última varredura, se ela for recente.
     */
    public function update(Request $request, Portfolio $portfolio, PortfolioFollower $follower): RedirectResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);
        abort_unless($portfolio->market === Market::Crypto, 422, 'Só carteiras de cripto usam o explorador.');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'size' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) config('explorer.follow.max_size'))],
            'min_hours' => ['required', 'integer', 'min:0', 'max:72'],
        ]);

        $wasEnabled = $portfolio->explorer_follow;

        $portfolio->update([
            'explorer_follow' => $data['enabled'],
            'explorer_follow_size' => $data['size'],
            'explorer_follow_min_hours' => $data['min_hours'],
        ]);

        AuditLog::record('user:'.$request->user()->id, 'explorer.follow_settings', $portfolio, $data);

        $applied = null;

        if ($data['enabled'] && ($scan = PortfolioFollower::latestScan()) !== null) {
            $applied = $follower->syncPortfolio($portfolio->refresh(), $scan);
        }

        PortfolioChangeBroadcaster::notify($portfolio->id, [PortfolioChanged::SCOPE_ASSETS]);

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            ! $data['enabled'] => $wasEnabled
                ? 'A carteira não segue mais o explorador. A lista atual fica como está e volta a ser sua.'
                : 'Configuração salva.',
            $applied === null => "Ligado. A lista vira o top {$data['size']} do explorador na próxima varredura.",
            default => sprintf('Ligado. %d entraram e %d saíram agora; a lista segue o explorador a cada varredura.', count($applied['added']), count($applied['removed'])),
        }]);

        return back();
    }
}
