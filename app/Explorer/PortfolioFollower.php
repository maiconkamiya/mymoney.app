<?php

namespace App\Explorer;

use App\Enums\Market;
use App\Enums\OrderStatus;
use App\Enums\SignalStage;
use App\Events\PortfolioChanged;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\OpportunityScan;
use App\Models\Portfolio;
use App\Trading\Assets\AssetRegistry;
use App\Trading\PortfolioChangeBroadcaster;
use App\Trading\PortfolioLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Seguir o explorador": a cada varredura, os ativos acompanhados da carteira
 * viram o ranking do explorador, até o limite da carteira. Entra quem está no
 * topo; sai quem caiu dele, depois da permanência mínima e só se nada estiver
 * em andamento no par nesta carteira. Retirar nunca vende: só deixa de comprar.
 * Sem IA: lê o ranking que a varredura já gravou.
 */
class PortfolioFollower
{
    public const AUDIT_ACTION = 'explorer.follow';

    public function __construct(private readonly AssetRegistry $registry) {}

    /**
     * Quantas linhas a varredura precisa gravar para as carteiras que seguem.
     */
    public static function rowsNeeded(): int
    {
        return (int) Portfolio::query()->where('explorer_follow', true)->where('market', Market::Crypto)->max('explorer_follow_size');
    }

    /**
     * A última varredura sem erro, se ainda for recente o bastante para valer.
     */
    public static function latestScan(): ?OpportunityScan
    {
        return OpportunityScan::query()
            ->whereNull('error')
            ->where('created_at', '>=', now()->subMinutes(3 * (int) config('explorer.interval_minutes')))
            ->latest('id')
            ->first();
    }

    /**
     * Todas as carteiras que seguem o explorador.
     *
     * @return array<int, array{added: list<string>, removed: list<string>, held: list<string>}>
     */
    public function sync(OpportunityScan $scan): array
    {
        $results = [];

        Portfolio::query()
            ->where('explorer_follow', true)
            ->where('market', Market::Crypto)
            ->get()
            ->each(function (Portfolio $portfolio) use ($scan, &$results): void {
                $results[$portfolio->id] = $this->syncPortfolio($portfolio, $scan);
            });

        return $results;
    }

    /**
     * @return array{added: list<string>, removed: list<string>, held: list<string>}
     */
    public function syncPortfolio(Portfolio $portfolio, OpportunityScan $scan): array
    {
        $empty = ['added' => [], 'removed' => [], 'held' => []];

        if ($scan->error !== null || ! $portfolio->explorer_follow || $portfolio->market !== Market::Crypto) {
            return $empty;
        }

        $size = max(1, $portfolio->explorer_follow_size);
        $ranking = $this->ranking($scan, $size);

        // Lista vazia quer dizer "acompanha todos": sem ranking, nada muda.
        if ($ranking->isEmpty()) {
            return $empty;
        }

        // Cadastro na Binance fora da trava: a trava é a mesma do motor micro.
        $tracked = $portfolio->assets()->pluck('assets.symbol')->all();
        $assets = [];

        foreach ($ranking as $opportunity) {
            if (! in_array($opportunity->symbol, $tracked, true) && ($asset = $this->asset($opportunity->symbol)) !== null) {
                $assets[$opportunity->symbol] = $asset;
            }
        }

        $result = PortfolioLock::run($portfolio->id, fn () => $this->apply($portfolio, $ranking, $assets, $size), wait: 20);

        if ($result === false) {
            Log::info("Seguir o explorador: carteira {$portfolio->id} ocupada, fica para a próxima varredura.");

            return $empty;
        }

        return $result;
    }

    /**
     * Mudanças recentes que o "Seguir o explorador" fez na carteira.
     *
     * @return list<array{at: string, kind: 'added'|'removed', symbol: string, reason: string}>
     */
    public static function history(Portfolio $portfolio, int $limit = 12): array
    {
        $rows = [];

        $logs = AuditLog::query()
            ->where('action', self::AUDIT_ACTION)
            ->where('subject_type', $portfolio->getMorphClass())
            ->where('subject_id', $portfolio->id)
            ->latest('id')
            ->limit($limit)
            ->get();

        foreach ($logs as $log) {
            foreach (['added', 'removed'] as $kind) {
                foreach ($log->payload[$kind] ?? [] as $change) {
                    $rows[] = [
                        'at' => $log->created_at->toIso8601String(),
                        'kind' => $kind,
                        'symbol' => (string) $change['symbol'],
                        'reason' => (string) $change['reason'],
                    ];
                }
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * Os primeiros do ranking que podem entrar, na ordem da varredura.
     *
     * @return Collection<int, Opportunity>
     */
    private function ranking(OpportunityScan $scan, int $size): Collection
    {
        $minScore = (float) config('explorer.follow.min_score');

        return $scan->opportunities()
            ->where('score', '>=', $minScore)
            ->get()
            ->reject(fn (Opportunity $o) => $this->endsTrading($o->symbol))
            ->take($size)
            ->values();
    }

    /**
     * @param  Collection<int, Opportunity>  $ranking
     * @param  array<string, Asset>  $assets  pares do ranking ainda fora da lista, já cadastrados
     * @return array{added: list<string>, removed: list<string>, held: list<string>}
     */
    private function apply(Portfolio $portfolio, Collection $ranking, array $assets, int $size): array
    {
        $bySymbol = $ranking->keyBy('symbol');
        $tracked = $portfolio->assets()->get();
        $since = CarbonImmutable::now()->subHours(max(0, $portfolio->explorer_follow_min_hours));

        $kept = 0;
        $removed = [];
        $held = [];

        foreach ($tracked as $asset) {
            if ($bySymbol->has($asset->symbol) && $asset->active) {
                $kept++;

                continue;
            }

            $reason = $asset->active ? $this->holdReason($portfolio, $asset, $since) : null;

            if ($reason !== null) {
                $held[] = ['symbol' => $asset->symbol, 'reason' => $reason];

                continue;
            }

            $portfolio->assets()->detach($asset->id);
            $removed[] = ['symbol' => $asset->symbol, 'reason' => 'Saiu do top '.$size.' do explorador.'];
        }

        $room = $size - $kept - count($held);
        $trackedSymbols = $tracked->pluck('symbol')->all();
        $added = [];

        foreach ($ranking as $opportunity) {
            if ($room <= 0) {
                break;
            }

            if (in_array($opportunity->symbol, $trackedSymbols, true)) {
                continue;
            }

            $asset = $assets[$opportunity->symbol] ?? null;

            if ($asset === null) {
                continue;
            }

            $portfolio->assets()->syncWithoutDetaching([$asset->id]);
            $asset->touch();
            $added[] = ['symbol' => $asset->symbol, 'reason' => sprintf('%dº do explorador, nota %s.', $opportunity->rank, number_format($opportunity->score, 0, ',', ''))];
            $room--;
        }

        if ($added !== [] || $removed !== []) {
            AuditLog::record('explorer', self::AUDIT_ACTION, $portfolio, ['added' => $added, 'removed' => $removed, 'held' => $held, 'size' => $size]);
            PortfolioChangeBroadcaster::notify($portfolio->id, [PortfolioChanged::SCOPE_ASSETS, PortfolioChanged::SCOPE_MICRO]);
        }

        return [
            'added' => array_column($added, 'symbol'),
            'removed' => array_column($removed, 'symbol'),
            'held' => array_column($held, 'symbol'),
        ];
    }

    /**
     * Por que o ativo que caiu do ranking continua na lista agora; null quando pode sair.
     */
    private function holdReason(Portfolio $portfolio, Asset $asset, CarbonImmutable $since): ?string
    {
        if ($portfolio->openPositions()->where('asset_id', $asset->id)->exists()) {
            return 'Posição aberta: sai depois que ela fechar.';
        }

        if ($portfolio->hasOpenMicroCycle($asset->id)) {
            return 'Ciclo micro aberto: sai depois que ele fechar.';
        }

        if ($portfolio->orders()->where('asset_id', $asset->id)->where('status', OrderStatus::Pending)->exists()) {
            return 'Ordem pendente: sai depois que ela terminar.';
        }

        if ($portfolio->signals()->where('asset_id', $asset->id)->whereIn('stage', [SignalStage::Scanned, SignalStage::Analyzed, SignalStage::Approved, SignalStage::Confirmed])->exists()) {
            return 'Sinal dos agentes em andamento.';
        }

        $enteredAt = $asset->getRelation('pivot')->getAttribute('created_at');

        if ($enteredAt !== null && CarbonImmutable::parse($enteredAt)->isAfter($since)) {
            return 'Na lista há menos de '.$portfolio->explorer_follow_min_hours.' h.';
        }

        return null;
    }

    private function endsTrading(string $symbol): bool
    {
        return Asset::query()
            ->where('market', Market::Crypto)
            ->where('symbol', $symbol)
            ->whereNotNull('trading_ends_on')
            ->exists();
    }

    private function asset(string $symbol): ?Asset
    {
        try {
            return $this->registry->discover($symbol, 'explorer');
        } catch (ValidationException $e) {
            Log::warning("Seguir o explorador não cadastrou {$symbol}: ".implode(' ', $e->validator->errors()->all()));

            return null;
        }
    }
}
