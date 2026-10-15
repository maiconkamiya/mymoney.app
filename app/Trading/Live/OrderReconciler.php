<?php

namespace App\Trading\Live;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Trading\BrokerManager;
use App\Trading\TradingHalt;
use Throwable;

/**
 * Compares recent real orders with what the broker reports. Any difference
 * (status or filled quantity) triggers the general halt for a person to look.
 */
class OrderReconciler
{
    public function __construct(
        private readonly BrokerManager $brokers,
        private readonly TradingHalt $halt,
    ) {}

    /**
     * @return list<string> one line per divergence found
     */
    public function reconcile(): array
    {
        $divergences = [];

        $orders = Order::query()
            ->with(['portfolio.brokerCredential', 'fills'])
            ->whereNotNull('external_id')
            ->whereHas('portfolio', fn ($query) => $query->where('mode', 'live'))
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Filled, OrderStatus::PartiallyFilled])
            ->where('created_at', '>=', now()->subDays(2))
            ->get();

        foreach ($orders as $order) {
            try {
                $remote = $this->brokers->for($order->portfolio)->getOrder((string) $order->external_id);
            } catch (Throwable $e) {
                $divergences[] = "Ordem {$order->id}: não foi possível consultar a corretora ({$e->getMessage()}).";

                continue;
            }

            $localQty = (float) $order->fills->sum('qty');

            if ($remote->status !== $order->status || abs($remote->filledQty - $localQty) > 1e-8) {
                $divergences[] = "Ordem {$order->id}: local {$order->status->value}/{$localQty}, corretora {$remote->status->value}/{$remote->filledQty}.";
            }
        }

        if ($divergences !== []) {
            AuditLog::record('reconciler', 'orders.divergence', payload: ['divergences' => $divergences]);
            $this->halt->halt('reconciler', 'Divergência na conciliação com a corretora.');
        }

        return $divergences;
    }
}
