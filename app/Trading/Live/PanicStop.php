<?php

namespace App\Trading\Live;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Portfolio;
use App\Trading\BrokerManager;
use App\Trading\Brokers\BinanceBroker;
use Throwable;

/**
 * "Parar tudo": turns automatic execution off, blocks every new order of the
 * portfolio and cancels the open orders this app left on the exchange.
 *
 * The block is saved first, so the stop holds even when the exchange is down.
 * Positions already open are left as they are; the app does not sell them.
 */
class PanicStop
{
    public function __construct(
        private readonly BrokerManager $brokers,
        private readonly LiveAlerts $alerts,
    ) {}

    /**
     * @return array{cancelled: list<string>, failed: list<string>}
     */
    public function stop(Portfolio $portfolio, string $actor, string $reason = 'Parar tudo acionado.'): array
    {
        $portfolio->forceFill([
            'auto_execute' => false,
            'live_auto_confirmed_at' => null,
            'orders_blocked_at' => now(),
            'orders_blocked_reason' => $reason,
        ])->save();

        $result = ['cancelled' => [], 'failed' => []];

        if ($portfolio->isLive()) {
            try {
                $broker = $this->brokers->for($portfolio);

                if ($broker instanceof BinanceBroker) {
                    $result = $broker->cancelOpenOrders();
                    $this->markCancelled($portfolio, $result['cancelled']);
                }
            } catch (Throwable $e) {
                $result['failed'][] = $e->getMessage();
            }
        }

        AuditLog::record($actor, 'portfolio.panic_stop', $portfolio, ['reason' => $reason] + $result);
        $this->alerts->panic($portfolio, $result);

        return $result;
    }

    public function resume(Portfolio $portfolio, string $actor): void
    {
        $portfolio->forceFill(['orders_blocked_at' => null, 'orders_blocked_reason' => null])->save();
        AuditLog::record($actor, 'portfolio.orders_released', $portfolio);
    }

    /**
     * @param  list<string>  $externalIds
     */
    private function markCancelled(Portfolio $portfolio, array $externalIds): void
    {
        if ($externalIds === []) {
            return;
        }

        Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('external_id', $externalIds)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'reject_reason' => 'Cancelada por "Parar tudo".']);
    }
}
