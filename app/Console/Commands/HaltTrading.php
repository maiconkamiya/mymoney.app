<?php

namespace App\Console\Commands;

use App\Trading\TradingHalt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading:halt {reason? : Why trading is being stopped} {--resume : Lift the halt instead}')]
#[Description('Stop (or resume) sending orders for every portfolio')]
class HaltTrading extends Command
{
    public function handle(TradingHalt $halt): int
    {
        if ($this->option('resume')) {
            $halt->resume('console');
            $this->info('Negociação retomada.');

            return self::SUCCESS;
        }

        $halt->halt('console', (string) $this->argument('reason'));
        $this->warn('Parada geral acionada. Nenhuma ordem será enviada.');

        return self::SUCCESS;
    }
}
