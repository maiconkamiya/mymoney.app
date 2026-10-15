<?php

namespace App\Console\Commands;

use App\Enums\PortfolioStatus;
use App\Models\Portfolio;
use App\Reports\SnapshotTaker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('portfolios:snapshot')]
#[Description('Grava o patrimônio diário de cada carteira em andamento')]
class SnapshotPortfolios extends Command
{
    public function handle(SnapshotTaker $snapshots): int
    {
        $count = 0;

        Portfolio::query()->where('status', PortfolioStatus::Running)->each(function (Portfolio $portfolio) use ($snapshots, &$count) {
            $snapshots->take($portfolio);
            $count++;
        });

        $this->info("{$count} snapshot(s) gravado(s).");

        return self::SUCCESS;
    }
}
