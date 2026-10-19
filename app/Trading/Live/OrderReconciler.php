<?php

namespace App\Trading\Live;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\AuditLog;
use App\Models\Order;
use App\Trading\BrokerManager;
use App\Trading\TradingHalt;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Compares recent real orders with what the broker reports. Any difference
 * (status or filled quantity) triggers the general halt for a person to look.
 * A network failure is not a difference: it is retried on the next runs and
 * only halts after `trading.live.reconcile_max_failures` in a row on one order.
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
            // A resting limit sell is pending by design and the exits job books its fill.
            ->whereNot(fn ($query) => $query->where('status', OrderStatus::Pending)->where('type', OrderType::Limit))
            ->get();

        foreach ($orders as $order) {
            try {
                $remote = $this->brokers->for($order->portfolio)->getOrder((string) $order->external_id);
            } catch (LiveTradingException $e) {
                if (! $e->uncertain) {
                    // The exchange answered with an error (unknown order, rejected key): that is a real difference.
                    $divergences[] = "Ordem {$order->id}: a corretora recusou a consulta ({$e->getMessage()}).";

                    continue;
                }

                $failures = $this->countFailure($order, $e);
                if ($failures >= $this->maxFailures()) {
                    $divergences[] = "Ordem {$order->id}: {$failures} consultas seguidas sem resposta da corretora ({$e->getMessage()}).";
                }

                continue;
            } catch (Throwable $e) {
                $divergences[] = "Ordem {$order->id}: não foi possível consultar a corretora ({$e->getMessage()}).";

                continue;
            }

            Cache::forget($this->failuresKey($order));

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

    /**
     * Records one failed query (timeout, no connection, 5xx) and returns how many in a row this order has.
     */
    private function countFailure(Order $order, LiveTradingException $e): int
    {
        $key = $this->failuresKey($order);
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addDay());

        AuditLog::record('reconciler', 'orders.reconcile_failed', $order, [
            'failures' => $failures,
            'max_failures' => $this->maxFailures(),
            'error' => $e->getMessage(),
        ]);

        return $failures;
    }

    private function failuresKey(Order $order): string
    {
        return "trading:reconcile:failures:{$order->id}";
    }

    private function maxFailures(): int
    {
        return max(1, (int) config('trading.live.reconcile_max_failures', 6));
    }
}
