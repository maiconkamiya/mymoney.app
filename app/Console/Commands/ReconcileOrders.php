<?php

namespace App\Console\Commands;

use App\Trading\Live\OrderReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading:reconcile')]
#[Description('Compare real orders with the broker and halt trading on any divergence')]
class ReconcileOrders extends Command
{
    public function handle(OrderReconciler $reconciler): int
    {
        $divergences = $reconciler->reconcile();

        foreach ($divergences as $line) {
            $this->error($line);
        }

        if ($divergences === []) {
            $this->info('Conciliação sem divergências.');

            return self::SUCCESS;
        }

        $this->warn('Parada geral acionada.');

        return self::FAILURE;
    }
}
