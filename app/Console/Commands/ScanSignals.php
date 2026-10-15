<?php

namespace App\Console\Commands;

use App\Agents\SignalScanner;
use App\Models\Asset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('signals:scan {symbol? : Only this asset}')]
#[Description('Open signals for running portfolios and send them to the agents')]
class ScanSignals extends Command
{
    public function handle(SignalScanner $scanner): int
    {
        $symbol = $this->argument('symbol');

        $query = Asset::query()->where('active', true);

        if (is_string($symbol)) {
            $query->where('symbol', strtoupper($symbol));
        }

        $assets = $query->get();

        $count = 0;

        foreach ($assets as $asset) {
            $count += count($scanner->scan($asset));
        }

        $this->info("{$count} sinais abertos.");

        return self::SUCCESS;
    }
}
