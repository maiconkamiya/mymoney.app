<?php

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Events\PortfolioChanged;
use App\Explorer\OpportunitySwarm;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\Portfolio;
use App\Trading\Assets\AssetRegistry;
use App\Trading\PortfolioChangeBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OpportunityController extends Controller
{
    /** Histórico baixado quando o dono passa a acompanhar um par achado. */
    private const HISTORY_DAYS = 30;

    /**
     * Passa a acompanhar o par na carteira: cadastra (ou tira a marca de achado),
     * põe na escolha da carteira e baixa 30 dias de velas.
     */
    public function track(Request $request, Opportunity $opportunity, AssetRegistry $registry): RedirectResponse
    {
        $portfolio = $this->portfolio($request);
        $actor = 'user:'.$request->user()->id;

        $asset = $registry->discover($opportunity->symbol, $actor);
        $asset->update(['discovered' => false]);

        // Carteira sem escolha acompanha todos os cadastrados: incluir um só a faria acompanhar só ele.
        if ($portfolio->assets()->exists()) {
            $portfolio->assets()->syncWithoutDetaching([$asset->id]);
            PortfolioChangeBroadcaster::notify($portfolio->id, [PortfolioChanged::SCOPE_ASSETS, PortfolioChanged::SCOPE_MICRO]);
        }

        Artisan::queue('market:backfill-binance', ['--symbol' => $asset->symbol, '--days' => self::HISTORY_DAYS]);
        AuditLog::record($actor, 'explorer.track', $portfolio, ['symbol' => $asset->symbol, 'opportunity_id' => $opportunity->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$asset->symbol} entrou nos ativos acompanhados de {$portfolio->name}. Os 30 dias de velas chegam em alguns minutos."]);

        return back();
    }

    /**
     * Manda o par aos agentes uma vez, sem acompanhar.
     */
    public function swarm(Request $request, Opportunity $opportunity, OpportunitySwarm $swarm): RedirectResponse
    {
        $portfolio = $this->portfolio($request);

        if (! $portfolio->isRunning()) {
            throw ValidationException::withMessages(['portfolio_id' => "{$portfolio->name} não está rodando: os agentes só analisam para carteiras ativas."]);
        }

        $asset = $swarm->manual($opportunity, $portfolio, 'user:'.$request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$asset->symbol} foi para os agentes de {$portfolio->name}. O sinal aparece na carteira quando a análise terminar."]);

        return back();
    }

    /**
     * Liga ou desliga o envio automático das melhores oportunidades aos agentes da carteira.
     */
    public function autoSwarm(Request $request, Portfolio $portfolio): RedirectResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);
        abort_unless($portfolio->market === Market::Crypto, 422, 'Só carteiras de cripto usam o explorador.');

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $portfolio->update(['explorer_auto_swarm' => $data['enabled']]);
        AuditLog::record('user:'.$request->user()->id, 'explorer.auto_swarm', $portfolio, ['enabled' => $data['enabled']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['enabled']
            ? "{$portfolio->name} recebe as melhores oportunidades nos agentes a cada varredura."
            : "{$portfolio->name} não recebe mais oportunidades automaticamente."]);

        return back();
    }

    private function portfolio(Request $request): Portfolio
    {
        $request->validate(['portfolio_id' => ['required', 'integer']]);

        $portfolio = Portfolio::query()->findOrFail($request->integer('portfolio_id'));

        abort_unless($portfolio->user_id === $request->user()->id, 403);
        abort_unless($portfolio->market === Market::Crypto, 422, 'Só carteiras de cripto usam o explorador.');

        return $portfolio;
    }
}
