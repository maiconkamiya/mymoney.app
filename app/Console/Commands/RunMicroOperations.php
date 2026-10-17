<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\Portfolio;
use App\Trading\Micro\MicroCosts;
use App\Trading\Micro\MicroRanker;
use App\Trading\Micro\MicroTrader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('micro:run {--explain : Só explica o que o motor faria agora, sem enviar ordens}')]
#[Description('Run the micro operations mode of every portfolio that has it on')]
class RunMicroOperations extends Command
{
    public function handle(MicroTrader $trader, MicroRanker $ranker, MicroCosts $costs): int
    {
        $portfolios = Portfolio::query()
            ->where('micro_enabled', true)
            ->where('market', Market::Crypto)
            ->get();

        if ($this->option('explain')) {
            return $this->explain($portfolios, $trader, $ranker, $costs);
        }

        $opened = 0;

        $portfolios->each(function (Portfolio $portfolio) use ($trader, &$opened) {
            $opened += $trader->run($portfolio);
        });

        $this->info("{$opened} micro operações abertas em {$portfolios->count()} carteira(s) com o modo ligado.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Portfolio>  $portfolios
     */
    private function explain($portfolios, MicroTrader $trader, MicroRanker $ranker, MicroCosts $costs): int
    {
        if ($portfolios->isEmpty()) {
            $this->warn('Nenhuma carteira de cripto com as micro operações ligadas. Ligue no card "Micro operações" e salve.');

            return self::SUCCESS;
        }

        foreach ($portfolios as $portfolio) {
            $state = $trader->state($portfolio);
            $gross = $costs->grossMarginPct($portfolio->micro_net_margin_pct);

            $this->line("Carteira #{$portfolio->id} {$portfolio->name}: {$state['label']} ({$state['code']})");
            $this->line("  aporte {$portfolio->micro_order_value}, margem líquida {$portfolio->micro_net_margin_pct}%, alvo bruto {$gross}%, última execução: ".($trader->lastRun($portfolio) ?? 'nunca'));

            $evaluation = $ranker->evaluate($portfolio, $gross);

            if ($evaluation['ready']->isEmpty() && $evaluation['skipped'] === []) {
                $this->warn('  Nenhum ativo de cripto ativo na carteira.');
            }

            $open = $portfolio->microCycles()->where('status', 'open')->count();

            foreach ($evaluation['ready'] as $row) {
                $setup = $row['setup'];
                $verdict = $setup->entry ? 'ENTRA' : 'não entra';
                $this->line(sprintf('  %s  faixa %.3f%%/min  %s  %s', $row['asset']->symbol, $setup->atrPct, $verdict, implode(' ', $setup->reasons)));

                if ($setup->entry) {
                    $block = $trader->gate($portfolio, $row['asset'], $setup, $open);
                    $this->line($block === null ? '      -> a ordem sairia no próximo minuto.' : "      -> BLOQUEADO: {$block}");
                }
            }

            if ($state['code'] === 'active' && ($block = $trader->lastBlock($portfolio)) !== null) {
                $this->line("  Último bloqueio gravado pela rodada real: {$block}");
            }

            foreach ($evaluation['skipped'] as $row) {
                $this->line("  {$row['symbol']}  sem leitura: {$row['reason']}");
            }
        }

        return self::SUCCESS;
    }
}
