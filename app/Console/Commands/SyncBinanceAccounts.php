<?php

namespace App\Console\Commands;

use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Models\Portfolio;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\LiveTradingException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('binance:sync')]
#[Description('Sincroniza saldos, posições e ordens das contas Binance com as carteiras dos donos, cada uma com a própria chave')]
class SyncBinanceAccounts extends Command
{
    public function handle(BinanceAccountSync $sync): int
    {
        $failed = 0;

        Portfolio::query()
            ->with('brokerCredential')
            ->where('broker', 'binance')
            ->where('mode', PortfolioMode::Live)
            ->where('status', PortfolioStatus::Running)
            ->each(function (Portfolio $portfolio) use ($sync, &$failed) {
                try {
                    $result = $sync->sync($portfolio);
                    $this->info("Carteira {$portfolio->id}: {$result['positions']} ativo(s), caixa {$result['cash']} USDT, {$result['orders']} ordem(ns) lida(s), {$result['settled']} venda(s) registrada(s).");
                } catch (LiveTradingException $e) {
                    $failed++;
                    $this->error("Carteira {$portfolio->id}: {$e->getMessage()}");
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
