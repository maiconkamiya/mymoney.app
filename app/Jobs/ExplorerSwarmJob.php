<?php

namespace App\Jobs;

use App\Agents\SignalScanner;
use App\Models\Asset;
use App\Models\Portfolio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Manda um par achado pelo explorador aos agentes de uma carteira. Antes traz as
 * velas dos últimos dias, para o Técnico não analisar um gráfico vazio nem a cotação
 * parecer velha para o scanner.
 */
class ExplorerSwarmJob implements ShouldQueue
{
    use Queueable;

    private const HISTORY_DAYS = 2;

    public int $tries = 1;

    public function __construct(public int $portfolioId, public int $assetId, public string $reason) {}

    public function handle(SignalScanner $scanner): void
    {
        $portfolio = Portfolio::query()->find($this->portfolioId);
        $asset = Asset::query()->find($this->assetId);

        if ($portfolio === null || $asset === null || ! $asset->active) {
            return;
        }

        // Dois dias de velas de 1 minuto (3 páginas da Binance): dão 5 m, 15 m e 1 h ao Técnico.
        Artisan::call('market:backfill-binance', ['--symbol' => $asset->symbol, '--days' => self::HISTORY_DAYS]);

        $scanner->scanPortfolio($portfolio, $asset, $this->reason);
    }
}
