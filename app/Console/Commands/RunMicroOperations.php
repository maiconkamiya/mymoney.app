<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\Portfolio;
use App\Trading\Micro\MicroTrader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('micro:run')]
#[Description('Run the micro operations mode of every portfolio that has it on')]
class RunMicroOperations extends Command
{
    public function handle(MicroTrader $trader): int
    {
        $opened = 0;

        Portfolio::query()
            ->where('micro_enabled', true)
            ->where('market', Market::Crypto)
            ->get()
            ->each(function (Portfolio $portfolio) use ($trader, &$opened) {
                $opened += $trader->run($portfolio);
            });

        $this->info("{$opened} micro operações abertas.");

        return self::SUCCESS;
    }
}
