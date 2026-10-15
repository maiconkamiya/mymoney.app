<?php

namespace App\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\SignalStage;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Risk\RiskGuard;
use Illuminate\Support\Facades\DB;

/**
 * The "executor" role: turns a confirmed signal into an order, passes it
 * through the risk guard and the portfolio's broker, and books the fill.
 */
class OrderExecutor
{
    public function __construct(
        private readonly BrokerManager $brokers,
        private readonly RiskGuard $risk,
        private readonly PriceFeed $prices,
        private readonly PortfolioValuation $valuation,
    ) {}

    public function execute(Signal $signal, string $actor): ?Order
    {
        $request = $this->buildRequest($signal);

        if ($request === null) {
            $signal->moveTo(SignalStage::Rejected, 'Sem cotação para dimensionar a ordem.');

            return null;
        }

        $outcome = $this->submit($signal->portfolio, $request, $signal, $actor, null);

        if (is_string($outcome)) {
            $signal->moveTo(SignalStage::Rejected, $outcome);

            return null;
        }

        if (in_array($outcome->status, [OrderStatus::Filled, OrderStatus::PartiallyFilled], true)) {
            $signal->moveTo(SignalStage::Executed);
        } else {
            $signal->moveTo(SignalStage::Rejected, 'Corretora recusou: '.$outcome->reject_reason);
        }

        return $outcome;
    }

    /**
     * Sell the whole open position at market ("stop", "target", "period_end"...).
     * The sell goes through the same risk guard and broker as any other order.
     */
    public function closePosition(Position $position, string $reason, string $actor): ?Order
    {
        $request = new OrderRequest(
            asset: $position->asset,
            side: OrderSide::Sell,
            qty: $position->qty,
            clientId: 'position-'.$position->id.'-'.$reason,
        );

        $outcome = $this->submit($position->portfolio, $request, $position->signal, $actor, $reason);

        if (is_string($outcome)) {
            AuditLog::record($actor, 'position.close_blocked', $position, ['reason' => $reason, 'detail' => $outcome]);

            return null;
        }

        return $outcome;
    }

    /**
     * Risk-check, send and book one order. Returns the order (filled or
     * rejected by the broker) or the reason the risk guard blocked it.
     */
    private function submit(Portfolio $portfolio, OrderRequest $request, ?Signal $signal, string $actor, ?string $reason): Order|string
    {
        $decision = $this->risk->check($portfolio, $request);

        if (! $decision->allowed) {
            AuditLog::record($actor, 'order.blocked', $signal, ['reasons' => $decision->reasons]);

            return 'Guarda de risco: '.$decision->summary();
        }

        $request = $decision->order;

        $order = Order::create([
            'portfolio_id' => $portfolio->id,
            'signal_id' => $signal?->id,
            'asset_id' => $request->asset->id,
            'broker' => $portfolio->broker,
            'side' => $request->side,
            'type' => $request->type,
            'qty' => $request->qty,
            'price' => $request->limitPrice,
            'stop_price' => $request->stopPrice,
            'status' => OrderStatus::Pending,
            'reason' => $reason,
        ]);

        $result = $this->brokers->for($portfolio)->placeOrder($request);

        DB::transaction(function () use ($order, $result, $signal, $portfolio, $request, $reason) {
            $order->update([
                'external_id' => $result->externalId,
                'status' => $result->status,
                'price' => $result->avgPrice ?? $order->price,
                'fees' => $result->fees,
                'reject_reason' => $result->rejectReason,
                'filled_at' => $result->filledAt,
            ]);

            if (! $result->isFilled()) {
                return;
            }

            $order->fills()->create([
                'qty' => $result->filledQty,
                'price' => $result->avgPrice,
                'fee' => $result->fees,
                'executed_at' => $result->filledAt ?? now(),
            ]);

            $this->book($portfolio, $request, $result, $signal, $reason);
        });

        AuditLog::record($actor, 'order.'.$order->status->value, $order, ['decision' => $decision->reasons, 'reason' => $reason]);

        return $order->refresh();
    }

    private function buildRequest(Signal $signal): ?OrderRequest
    {
        $portfolio = $signal->portfolio;
        $quote = $this->prices->quote($signal->asset);

        if ($quote === null) {
            return null;
        }

        $qty = $signal->side === OrderSide::Sell
            ? (float) ($portfolio->openPositions()->where('asset_id', $signal->asset_id)->value('qty') ?? 0)
            : $portfolio->riskProfile->max_order_pct * $this->valuation->equity($portfolio) / $quote->price;

        return new OrderRequest(
            asset: $signal->asset,
            side: $signal->side,
            qty: $qty,
            stopPrice: $signal->stop_price,
            clientId: 'signal-'.$signal->id,
        );
    }

    private function book(Portfolio $portfolio, OrderRequest $request, OrderResult $result, ?Signal $signal, ?string $reason): void
    {
        $value = $result->filledQty * $result->avgPrice;

        $position = $portfolio->openPositions()->where('asset_id', $request->asset->id)->lockForUpdate()->first();

        if ($request->side === OrderSide::Buy) {
            $portfolio->decrement('cash', $value + $result->fees);

            if ($position === null) {
                $portfolio->positions()->create([
                    'asset_id' => $request->asset->id,
                    'qty' => $result->filledQty,
                    'avg_price' => $result->avgPrice,
                    'signal_id' => $signal?->id,
                    'stop_price' => $request->stopPrice,
                    'target_price' => $signal?->target_price,
                    'opened_at' => $result->filledAt ?? now(),
                ]);

                return;
            }

            $qty = $position->qty + $result->filledQty;
            $position->update([
                'avg_price' => ($position->qty * $position->avg_price + $value) / $qty,
                'qty' => $qty,
                'stop_price' => $request->stopPrice ?? $position->stop_price,
                'target_price' => $signal->target_price ?? $position->target_price,
            ]);

            return;
        }

        $portfolio->increment('cash', $value - $result->fees);
        $remaining = round($position->qty - $result->filledQty, 8);

        $position->update([
            'qty' => $remaining,
            'realized_pnl' => $position->realized_pnl + ($result->avgPrice - $position->avg_price) * $result->filledQty - $result->fees,
            'closed_at' => $remaining <= 0 ? ($result->filledAt ?? now()) : null,
            'exit_reason' => $remaining <= 0 ? ($reason ?? 'signal') : null,
        ]);

        if ($remaining <= 0 && $position->signal_id !== null) {
            $position->signal?->moveTo(SignalStage::Closed, $reason !== null ? 'Posição encerrada: '.$reason.'.' : null);
        }
    }
}
