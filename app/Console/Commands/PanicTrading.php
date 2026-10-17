<?php

namespace App\Console\Commands;

use App\Enums\PortfolioMode;
use App\Models\Portfolio;
use App\Trading\Live\PanicStop;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading:panic {portfolio? : Id da carteira} {--all : Todas as carteiras reais e com execução automática} {--resume : Libera as ordens da carteira em vez de parar}')]
#[Description('Parar tudo: desliga a execução automática, cancela ordens abertas na Binance e bloqueia novas ordens')]
class PanicTrading extends Command
{
    public function handle(PanicStop $panic): int
    {
        $id = $this->argument('portfolio');

        if ($this->option('resume')) {
            if (! is_numeric($id)) {
                $this->error('Informe o id da carteira para liberar as ordens.');

                return self::INVALID;
            }

            $portfolio = Portfolio::find((int) $id);

            if ($portfolio === null) {
                $this->error('Carteira não encontrada.');

                return self::FAILURE;
            }

            $panic->resume($portfolio, 'console');
            $this->info("Ordens liberadas na carteira {$portfolio->id}. A execução automática continua desligada.");

            return self::SUCCESS;
        }

        $portfolios = match (true) {
            is_numeric($id) => Portfolio::query()->whereKey((int) $id)->get(),
            (bool) $this->option('all') => Portfolio::query()->where(fn ($query) => $query->where('mode', PortfolioMode::Live)->orWhere('auto_execute', true))->get(),
            default => collect(),
        };

        if ($portfolios->isEmpty()) {
            $this->error('Informe o id da carteira ou use --all.');

            return self::INVALID;
        }

        $failed = false;

        foreach ($portfolios as $portfolio) {
            $result = $panic->stop($portfolio, 'console', 'Comando trading:panic.');
            $this->warn("Carteira {$portfolio->id} ({$portfolio->name}): parada; ordens canceladas: ".count($result['cancelled']).'.');

            foreach ($result['failed'] as $line) {
                $failed = true;
                $this->error('  Falha: '.$line);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
