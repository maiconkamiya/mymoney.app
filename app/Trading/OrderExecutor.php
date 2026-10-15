<?php

namespace App\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\SignalStage;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Portfolio;
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
        $portfolio = $signal->portfolio;
        $request = $this->buildRequest($signal);

        if ($request === null) {
            $signal->moveTo(SignalStage::Rejected, 'Sem cotação para dimensionar a ordem.');

            return null;
        }

        $decision = $this->risk->check($portfolio, $request);

        if (! $decision->allowed) {
            $signal->moveTo(SignalStage::Rejected, 'Guarda de risco: '.$decision->summary());
            AuditLog::record($actor, 'order.blocked', $signal, ['reasons' => $decision->reasons]);

            return null;
        }

        $request = $decision->order;

        $order = Order::create([
            'portfolio_id' => $portfolio->id,
            'signal_id' => $signal->id,
            'asset_id' => $request->asset->id,
            'broker' => $portfolio->broker,
            'side' => $request->side,
            'type' => $request->type,
            'qty' => $request->qty,
            'price' => $request->limitPrice,
            'stop_price' => $request->stopPrice,
            'status' => OrderStatus::Pending,
        ]);

        $result = $this->brokers->for($portfolio)->placeOrder($request);

        DB::transaction(function () use ($order, $result, $signal, $portfolio, $request) {
            $order->update([
                'external_id' => $result->externalId,
                'status' => $result->status,
                'price' => $result->avgPrice ?? $order->price,
                'fees' => $result->fees,
                'reject_reason' => $result->rejectReason,
                'filled_at' => $result->filledAt,
            ]);

            if (! $result->isFilled()) {
                $signal->moveTo(SignalStage::Rejected, 'Corretora recusou: '.$result->rejectReason);

                return;
            }

            $order->fills()->create([
                'qty' => $result->filledQty,
                'price' => $result->avgPrice,
                'fee' => $result->fees,
                'executed_at' => $result->filledAt ?? now(),
            ]);

            $this->book($portfolio, $request, $result);
            $signal->moveTo(SignalStage::Executed);
        });

        AuditLog::record($actor, 'order.'.$order->status->value, $order, ['decision' => $decision->reasons]);

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

    private function book(Portfolio $portfolio, OrderRequest $request, OrderResult $result): void
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
                    'stop_price' => $request->stopPrice,
                    'opened_at' => $result->filledAt ?? now(),
                ]);

                return;
            }

            $qty = $position->qty + $result->filledQty;
            $position->update([
                'avg_price' => ($position->qty * $position->avg_price + $value) / $qty,
                'qty' => $qty,
                'stop_price' => $request->stopPrice ?? $position->stop_price,
            ]);

            return;
        }

        $portfolio->increment('cash', $value - $result->fees);
        $remaining = round($position->qty - $result->filledQty, 8);

        $position->update([
            'qty' => $remaining,
            'realized_pnl' => $position->realized_pnl + ($result->avgPrice - $position->avg_price) * $result->filledQty - $result->fees,
            'closed_at' => $remaining <= 0 ? ($result->filledAt ?? now()) : null,
        ]);
    }
}
