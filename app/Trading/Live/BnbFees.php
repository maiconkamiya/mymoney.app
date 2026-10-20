<?php

namespace App\Trading\Live;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Portfolio;
use App\Trading\Micro\MicroTrader;

/**
 * Whether a real Binance portfolio is paying its fees in BNB with the
 * discount, and for how long the BNB on the account still pays them. Binance
 * goes back to charging in the traded coin without warning once the BNB is
 * not enough, so the screen says it before that happens. Only reads what the
 * account sync and the orders already stored: nothing is sent to Binance.
 */
class BnbFees
{
    /**
     * @return array{state: string, enabled: bool|null, bnb_qty: float, bnb_value: float, cycles: int|null, last_fee_asset: string|null, last_fee_at: string|null, message: string}|null
     */
    public function for(Portfolio $portfolio): ?array
    {
        if (! $portfolio->isLive() || $portfolio->broker !== 'binance') {
            return null;
        }

        $report = $portfolio->sync_report ?? [];
        $enabled = $report['bnb_discount'] ?? null;
        $bnb = collect($report['holdings'] ?? [])->firstWhere('coin', 'BNB');
        $qty = (float) ($bnb['qty'] ?? 0);
        $value = (float) ($bnb['value'] ?? 0);

        $last = Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNotNull('external_id')
            ->whereNotNull('fee_asset')
            ->whereIn('status', [OrderStatus::Filled, OrderStatus::PartiallyFilled])
            ->latest('filled_at')
            ->first(['fee_asset', 'filled_at']);

        $lastAsset = $last?->fee_asset;
        $cycles = ($perCycle = $this->cycleFee($portfolio)) > 0 ? (int) floor($value / $perCycle) : null;
        $minCycles = (int) config('trading.costs.crypto.bnb_min_cycles');

        [$state, $message] = match (true) {
            $enabled === false => ['desligado', 'Desconto em BNB desligado na Binance: a taxa sai na moeda negociada (0,1% por lado). Ligue "Usar BNB para pagar taxas" e mantenha BNB no Spot para pagar 0,075%.'],
            $enabled === true && $value <= 0 => ['sem_bnb', 'Desconto em BNB ligado, mas não há BNB no Spot: a Binance cobra a taxa na moeda negociada.'],
            $enabled === true && $lastAsset !== null && $lastAsset !== 'BNB' => ['sem_bnb', sprintf('Desconto em BNB ligado, mas a última ordem pagou a taxa em %s: o BNB não cobriu a taxa ou o desconto foi ligado depois dela.', $lastAsset)],
            ($enabled === true || $lastAsset === 'BNB') && $cycles !== null && $cycles < $minCycles => ['acabando', sprintf('BNB acabando: %s BNB (%.2f USDT) pagam cerca de %d ciclos. Sem BNB a Binance volta a cobrar na moeda, sem avisar.', $this->qty($qty), $value, $cycles)],
            $enabled === true || $lastAsset === 'BNB' => ['ativo', sprintf('Taxa paga em BNB com desconto (0,075%% por lado). %s BNB (%.2f USDT) pagam cerca de %s ciclos.', $this->qty($qty), $value, $cycles ?? '?')],
            default => ['desconhecido', 'Ainda não se sabe se a taxa sai em BNB: a próxima sincronização ou ordem executada diz.'],
        };

        return [
            'state' => $state,
            'enabled' => $enabled,
            'bnb_qty' => $qty,
            'bnb_value' => round($value, 2),
            'cycles' => $cycles,
            'last_fee_asset' => $lastAsset,
            'last_fee_at' => $last?->filled_at?->toIso8601String(),
            'message' => $message,
        ];
    }

    /**
     * Fee of one micro cycle (buy and sell of the order value) paid in BNB.
     */
    private function cycleFee(Portfolio $portfolio): float
    {
        $value = max((float) $portfolio->micro_order_value, MicroTrader::minOrderValue($portfolio));

        return 2 * $value * (float) config('trading.costs.crypto.fee_rate') * (1 - (float) config('trading.costs.crypto.bnb_discount'));
    }

    private function qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 6, ',', '.'), '0'), ',');
    }
}
