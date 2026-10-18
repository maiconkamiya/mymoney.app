<?php

namespace App\Explorer;

use App\Enums\Market;
use App\MarketData\BinanceKlines;
use App\MarketData\BinanceSymbols;
use App\MarketData\BinanceTickers;
use App\Models\Asset;
use App\Models\OpportunityScan;
use App\Trading\Assets\AssetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Uma varredura completa: ticker de 24 h de todos os pares (1 chamada), filtro
 * barato, velas de 1 h só das melhores (até `deep_candidates` chamadas), nota,
 * explicação opcional por IA (1 chamada), envio automático aos agentes e limpeza.
 */
class OpportunityExplorer
{
    public function __construct(
        private readonly BinanceTickers $tickers,
        private readonly BinanceSymbols $symbols,
        private readonly BinanceKlines $klines,
        private readonly OpportunityRanker $ranker,
        private readonly OpportunityNarrator $narrator,
        private readonly OpportunitySwarm $swarm,
        private readonly AssetRegistry $registry,
    ) {}

    /**
     * @return array{scan: OpportunityScan, swarmed: list<string>}
     */
    public function run(): array
    {
        try {
            $bases = array_column($this->symbols->catalog(), 'base', 'symbol');
            $tickers = $this->tickers->all();
        } catch (Throwable $e) {
            return ['scan' => OpportunityScan::create(['error' => 'Binance indisponível: '.$e->getMessage()]), 'swarmed' => []];
        }

        $passed = $this->ranker->filter($tickers, $bases);
        $scored = [];

        foreach (array_slice($passed, 0, max(0, (int) config('explorer.deep_candidates'))) as $pair) {
            try {
                $result = $this->ranker->score($pair, $this->klines->hourly($pair['symbol'], 48));
            } catch (Throwable $e) {
                Log::info("Explorador sem velas de 1 h de {$pair['symbol']}: {$e->getMessage()}");

                continue;
            }

            if ($result !== null) {
                $scored[] = $pair + $result;
            }
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, max(1, (int) config('explorer.top')));

        $narration = $this->narrator->narrate($top);
        $top = $this->reorder($top, $narration['order'] ?? []);

        $scan = DB::transaction(function () use ($tickers, $passed, $top, $narration): OpportunityScan {
            $scan = OpportunityScan::create([
                'pairs_seen' => count($tickers),
                'pairs_passed' => count($passed),
                'ai_model' => $narration['response']->model ?? null,
                'ai_cost' => isset($narration['response']) ? $narration['response']->cost() : 0,
            ]);

            foreach ($top as $index => $row) {
                $scan->opportunities()->create([
                    'symbol' => $row['symbol'],
                    'base' => $row['base'],
                    'rank' => $index + 1,
                    'score' => $row['score'],
                    'price' => $row['price'],
                    'change_24h_pct' => $row['change_pct'],
                    'quote_volume' => $row['quote_volume'],
                    'spread_pct' => $row['spread_pct'],
                    'volume_accel' => $row['volume_accel'],
                    'breakout' => $row['breakout'],
                    'extension_atr' => $row['extension_atr'],
                    'reasons' => $row['reasons'],
                    'ai_note' => $narration['notes'][$row['symbol']] ?? null,
                ]);
            }

            return $scan;
        });

        $swarmed = $this->swarm->auto($scan);
        $this->prune(array_column($top, 'symbol'));

        return ['scan' => $scan, 'swarmed' => $swarmed];
    }

    /**
     * A ordem da IA para os pares que ela devolveu, depois os demais pela nota.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $order
     * @return list<array<string, mixed>>
     */
    private function reorder(array $rows, array $order): array
    {
        $position = array_flip($order);

        usort($rows, fn (array $a, array $b) => [$position[$a['symbol']] ?? PHP_INT_MAX, -$a['score']] <=> [$position[$b['symbol']] ?? PHP_INT_MAX, -$b['score']]);

        return $rows;
    }

    /**
     * Varreduras antigas saem; pares achados só pelo explorador, que ninguém
     * acompanhou e sumiram do topo, saem depois de `discovered_ttl_hours`.
     *
     * @param  list<string>  $current
     */
    private function prune(array $current): void
    {
        OpportunityScan::query()->where('created_at', '<', now()->subDays((int) config('explorer.keep_days')))->delete();

        $stale = Asset::query()
            ->where('market', Market::Crypto)
            ->where('active', true)
            ->where('discovered', true)
            ->whereNotIn('symbol', $current)
            ->where('updated_at', '<', now()->subHours((int) config('explorer.discovered_ttl_hours')))
            ->get();

        foreach ($stale as $asset) {
            try {
                $this->registry->remove($asset, 'explorer');
            } catch (ValidationException) {
                // Posição, sinal ou ordem em andamento: fica até terminar.
            }
        }
    }
}
