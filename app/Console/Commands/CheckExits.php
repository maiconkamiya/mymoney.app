<?php

namespace App\Console\Commands;

use App\Trading\PositionExits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('positions:check-exits')]
#[Description('Sell positions that hit stop or target and finish portfolios whose period ended')]
class CheckExits extends Command
{
    public function handle(PositionExits $exits): int
    {
        $closed = $exits->checkStopsAndTargets();
        $finished = $exits->finishExpired();

        $this->info("{$closed} posições encerradas, {$finished} carteiras finalizadas.");

        return self::SUCCESS;
    }
}
