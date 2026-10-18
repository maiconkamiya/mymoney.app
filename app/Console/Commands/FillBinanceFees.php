<?php

namespace App\Console\Commands;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Trading\BrokerManager;
use App\Trading\Brokers\BinanceBroker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executed sales on a real portfolio that were booked with no fee (the resting
 * sells before #170) get the commission Binance charged, read from the order's
 * trades; the micro cycle and the position they closed are recalculated. The
 * cash is left to the account sync, which reads it from Binance.
 */
#[Signature('binance:fill-fees {portfolio? : Id da carteira (padrão: todas as reais)} {--dry-run : Só mostra o que seria corrigido}')]
#[Description('Preenche a taxa das vendas na Binance gravadas com taxa zero')]
class FillBinanceFees extends Command
{
    public function handle(BrokerManager $brokers): int
    {
        $portfolios = Portfolio::query()
            ->where('mode', 'live')
            ->where('broker', 'binance')
            ->when($this->argument('portfolio') !== null, fn ($query) => $query->whereKey($this->argument('portfolio')))
            ->get();

        $filled = 0;

        foreach ($portfolios as $portfolio) {
            $orders = Order::query()
                ->with('asset')
                ->where('portfolio_id', $portfolio->id)
                ->where('side', OrderSide::Sell)
                ->where('status', OrderStatus::Filled)
                ->where('fees', 0)
                ->whereNotNull('external_id')
                ->orderBy('filled_at')
                ->get();

            foreach ($orders as $order) {
                try {
                    $broker = $brokers->for($portfolio);
                    $fees = $broker instanceof BinanceBroker ? $broker->orderFees((string) $order->external_id) : null;
                } catch (Throwable $e) {
                    $this->warn("Carteira {$portfolio->id}: {$e->getMessage()}");

                    continue 2;
                }

                if ($fees === null || $fees <= 0) {
                    $this->line("Ordem {$order->id} ({$order->asset->symbol}): sem taxa na Binance, fica como está.");

                    continue;
                }

                $this->line(sprintf('Ordem %d (%s): taxa %.8f USDT.', $order->id, $order->asset->symbol, $fees));
                $filled++;

                if (! $this->option('dry-run')) {
                    $this->apply($order, $fees);
                }
            }
        }

        $this->info($this->option('dry-run') ? "{$filled} venda(s) seriam corrigidas." : "{$filled} venda(s) corrigidas.");

        return self::SUCCESS;
    }

    private function apply(Order $order, float $fees): void
    {
        DB::transaction(function () use ($order, $fees): void {
            $order->update(['fees' => $fees]);
            // The resting sale is booked as one fill.
            $order->fills()->where('fee', 0)->first()?->update(['fee' => $fees]);

            if ($order->position_id === null) {
                return;
            }

            $order->position()->decrement('realized_pnl', $fees);

            // The cycle this sale closed: a cycle has its own position.
            $cycle = MicroCycle::query()
                ->where('position_id', $order->position_id)
                ->where('status', 'closed')
                ->latest('closed_at')
                ->first();

            if ($cycle !== null) {
                $cycle->update([
                    'fees' => $cycle->fees + $fees,
                    'net_pnl' => $cycle->net_pnl === null ? null : $cycle->net_pnl - $fees,
                ]);
            }
        });

        AuditLog::record('binance:fill-fees', 'order.fee_filled', $order, ['portfolio_id' => $order->portfolio_id, 'fees' => $fees]);
    }
}
