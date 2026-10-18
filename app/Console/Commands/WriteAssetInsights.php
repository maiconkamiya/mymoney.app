<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Insights\AssetInsightWriter;
use App\Models\Asset;
use App\Models\Portfolio;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('insights:write {symbol? : Só este par, como BTCUSDT}')]
#[Description('Escreve a análise do dia ("por que está em alta ou em queda") dos ativos acompanhados')]
class WriteAssetInsights extends Command
{
    public function handle(AssetInsightWriter $writer): int
    {
        if (! config('insights.ai.enabled')) {
            $this->line('Análise do dia desligada em Custos e serviços.');

            return self::SUCCESS;
        }

        $symbol = $this->argument('symbol');
        $symbol = is_string($symbol) ? strtoupper($symbol) : null;
        $assets = Asset::query()
            ->where('market', Market::Crypto)
            ->where('active', true)
            ->where('is_benchmark', false)
            ->when($symbol !== null, fn ($q) => $q->where('symbol', $symbol))
            ->orderBy('symbol')
            ->get()
            ->filter(fn (Asset $asset) => Portfolio::query()->where('market', Market::Crypto)->tracking($asset)->exists());

        $cost = 0.0;

        foreach ($assets as $asset) {
            $insight = $writer->write($asset);

            if ($insight === null) {
                $this->warn("{$asset->symbol}: sem análise (veja o log).");

                continue;
            }

            $cost += $insight->cost;
            $this->line("{$asset->symbol}: {$insight->summary}");
        }

        $this->info(sprintf('%d ativos, custo US$ %.4f.', $assets->count(), $cost));

        return self::SUCCESS;
    }
}
