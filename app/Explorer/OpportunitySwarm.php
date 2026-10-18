<?php

namespace App\Explorer;

use App\Enums\Market;
use App\Enums\PortfolioStatus;
use App\Jobs\ExplorerSwarmJob;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\OpportunityScan;
use App\Models\Portfolio;
use App\Trading\Assets\AssetRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Leva oportunidades do explorador aos agentes. O explorador nunca compra: o par
 * vira um sinal comum e passa pelo consenso, pela guarda de risco e, na carteira
 * real, pelos limites por ordem e por dia.
 */
class OpportunitySwarm
{
    public function __construct(private readonly AssetRegistry $registry) {}

    /**
     * As melhores da varredura, nas carteiras que ligaram o envio automático,
     * respeitando a nota mínima, o intervalo por par e o limite por dia.
     *
     * @return list<string> pares enviados
     */
    public function auto(OpportunityScan $scan): array
    {
        $config = config('explorer.swarm');

        $portfolios = Portfolio::query()
            ->where('explorer_auto_swarm', true)
            ->where('status', PortfolioStatus::Running)
            ->where('market', Market::Crypto)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->get();

        if ($portfolios->isEmpty() || $config['top'] <= 0) {
            return [];
        }

        $budget = $config['max_per_day'] - Opportunity::query()->where('swarm_sent_at', '>=', now()->startOfDay())->count();
        $sent = [];

        $candidates = $scan->opportunities()
            ->where('score', '>=', $config['min_score'])
            ->limit($config['top'])
            ->get();

        foreach ($candidates as $opportunity) {
            if ($budget <= 0) {
                break;
            }

            if ($this->recentlySent($opportunity->symbol, $config['cooldown_hours'])) {
                continue;
            }

            $asset = $this->asset($opportunity);

            if ($asset === null || $asset->tradingEnded()) {
                continue;
            }

            $dispatched = 0;

            foreach ($portfolios as $portfolio) {
                // A carteira que já acompanha o par recebe o scanner normal a cada 15 min.
                if (Portfolio::query()->whereKey($portfolio->id)->tracking($asset)->exists()) {
                    continue;
                }

                ExplorerSwarmJob::dispatch($portfolio->id, $asset->id, $this->reason($opportunity));
                $dispatched++;
            }

            if ($dispatched > 0) {
                $opportunity->update(['swarm_sent_at' => now()]);
                $asset->touch();
                $sent[] = $opportunity->symbol;
                $budget--;
            }
        }

        if ($sent !== []) {
            AuditLog::record('explorer', 'explorer.swarm', $scan, ['symbols' => $sent, 'portfolios' => $portfolios->pluck('id')->all()]);
        }

        return $sent;
    }

    /**
     * Envio pedido pelo dono da carteira na tela de Ativos.
     *
     * @throws ValidationException quando a Binance não conhece o par
     */
    public function manual(Opportunity $opportunity, Portfolio $portfolio, string $actor): Asset
    {
        $asset = $this->registry->discover($opportunity->symbol, $actor);

        if ($asset->tradingEnded()) {
            throw ValidationException::withMessages(['opportunity' => "A negociação de {$asset->symbol} foi encerrada: o par não vai mais aos agentes."]);
        }

        $asset->touch();

        ExplorerSwarmJob::dispatch($portfolio->id, $asset->id, $this->reason($opportunity));
        $opportunity->update(['swarm_sent_at' => now()]);

        AuditLog::record($actor, 'explorer.swarm', $portfolio, ['symbol' => $opportunity->symbol, 'opportunity_id' => $opportunity->id]);

        return $asset;
    }

    private function recentlySent(string $symbol, int $hours): bool
    {
        return Opportunity::query()
            ->where('symbol', $symbol)
            ->where('swarm_sent_at', '>', now()->subHours($hours))
            ->exists();
    }

    private function asset(Opportunity $opportunity): ?Asset
    {
        try {
            return $this->registry->discover($opportunity->symbol, 'explorer');
        } catch (ValidationException $e) {
            Log::warning("Explorador não cadastrou {$opportunity->symbol}: ".implode(' ', $e->validator->errors()->all()));

            return null;
        }
    }

    private function reason(Opportunity $opportunity): string
    {
        return sprintf('Explorador: %dº da varredura, nota %s.', $opportunity->rank, number_format($opportunity->score, 0, ',', ''));
    }
}
