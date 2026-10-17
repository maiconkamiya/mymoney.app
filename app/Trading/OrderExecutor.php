<?php

namespace App\Trading;

use App\Agents\AgentActivityBroadcaster;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SignalStage;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\LiveAlerts;
use App\Trading\Live\LiveTradingException;
use App\Trading\Risk\RiskGuard;
use Illuminate\Database\Eloquent\Collection;
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
        private readonly AgentActivityBroadcaster $broadcaster,
        private readonly LiveAlerts $alerts,
    ) {}

    public function execute(Signal $signal, string $actor): ?Order
    {
        $this->broadcaster->orderStarted($signal);

        try {
            return $this->place($signal, $actor);
        } finally {
            $this->broadcaster->orderFinished($signal);
        }
    }

    private function place(Signal $signal, string $actor): ?Order
    {
        $request = $this->buildRequest($signal);

        if ($request === null) {
            $signal->moveTo(SignalStage::Rejected, 'Sem cotação para dimensionar a ordem.');

            return null;
        }

        if ($signal->portfolio->isLive()) {
            $signal->portfolio->refresh();
        }

        if ($signal->portfolio->isLive() && ! $this->approvedForLive($signal)) {
            $signal->moveTo(SignalStage::Rejected, 'Ordem real exige aprovação manual ou execução automática confirmada pelo dono.');
            AuditLog::record($actor, 'order.blocked', $signal, ['reasons' => ['Sem aprovação manual nem execução automática confirmada.']]);

            return null;
        }

        // One real order per signal: a repeated run (retry, double click) never sends a second one.
        if ($signal->portfolio->isLive()) {
            $existing = $signal->orders()->whereNotIn('status', [OrderStatus::Rejected, OrderStatus::Cancelled])->first();

            if ($existing !== null) {
                AuditLog::record($actor, 'order.duplicate_ignored', $signal, ['order_id' => $existing->id]);

                return $existing;
            }
        }

        $outcome = $this->submit($signal->portfolio, $request, $signal, $actor, null);

        if (is_string($outcome)) {
            $signal->moveTo(SignalStage::Rejected, $outcome);

            if ($signal->portfolio->isLive()) {
                $this->alerts->refused($signal->portfolio, $signal, $outcome);
            }

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
     * Exits only reduce risk, so they need no fresh approval, but on real portfolios
     * they still pass the live switch, the halt and the credential checks.
     * The sell goes through the same risk guard and broker as any other order.
     */
    public function closePosition(Position $position, string $reason, string $actor): ?Order
    {
        // A resting limit sell locks the coins: it goes first, and what it already sold counts.
        if (! $this->cancelResting($position, $actor)) {
            $this->logCloseBlocked($position, $actor, ['reason' => $reason, 'detail' => 'A venda limitada aberta não pôde ser cancelada na corretora.']);

            return null;
        }

        $position->refresh();

        if ($position->closed_at !== null) {
            return Order::query()
                ->where('portfolio_id', $position->portfolio_id)
                ->where('asset_id', $position->asset_id)
                ->where('side', OrderSide::Sell)
                ->where('status', OrderStatus::Filled)
                ->latest('id')
                ->first();
        }

        $request = new OrderRequest(
            asset: $position->asset,
            side: OrderSide::Sell,
            qty: $this->sellableQty($position),
            clientId: 'position-'.$position->id.'-'.$reason,
        );

        $outcome = $this->submit($position->portfolio, $request, $position->signal, $actor, $reason);

        if (is_string($outcome)) {
            $this->logCloseBlocked($position, $actor, ['reason' => $reason, 'detail' => $outcome]);

            return null;
        }

        return $outcome;
    }

    /**
     * The exits job retries every minute; log the same block once an hour, not once a minute.
     *
     * @param  array<string, string>  $payload
     */
    private function logCloseBlocked(Position $position, string $actor, array $payload): void
    {
        $repeated = AuditLog::query()
            ->where('action', 'position.close_blocked')
            ->where('subject_type', $position->getMorphClass())
            ->where('subject_id', $position->getKey())
            ->where('created_at', '>=', now()->subHour())
            ->get()
            ->contains(fn (AuditLog $log) => $log->payload === $payload);

        if (! $repeated) {
            AuditLog::record($actor, 'position.close_blocked', $position, $payload);
        }
    }

    /**
     * Leave a limit sell of the whole position resting at `$price` (the micro
     * operations target, registered right after the buy). It passes the risk
     * guard like any order. The quantity is what can really be sold and the
     * price goes up to the pair's tick, so the margin is never cut.
     */
    public function placeRestingSell(Position $position, float $price, string $reason, string $actor): Order|string
    {
        $asset = $position->asset;
        $tick = (float) $asset->tick_size;
        $limit = $tick > 0 ? round(ceil(round($price / $tick, 8)) * $tick, 8) : $price;
        $qty = $this->sellableQty($position);

        if ($qty <= 0) {
            return 'Quantidade vendável zerada pelo passo do par.';
        }

        $minimum = $position->portfolio->isLive() ? (float) $asset->min_notional : 0.0;

        if ($qty * $limit < $minimum) {
            return sprintf('A venda de %s a %s vale %.2f USDT, abaixo do mínimo de %.2f USDT do par.', $qty, $limit, $qty * $limit, $minimum);
        }

        $request = new OrderRequest(
            asset: $asset,
            side: OrderSide::Sell,
            qty: $qty,
            type: OrderType::Limit,
            limitPrice: $limit,
            clientId: 'position-'.$position->id.'-'.$reason,
        );

        return $this->submit($position->portfolio, $request, null, $actor, $reason);
    }

    /**
     * Limit sells of the position still resting at the broker.
     *
     * @return Collection<int, Order>
     */
    public function restingSells(Position $position): Collection
    {
        return Order::query()
            ->with('asset')
            ->where('portfolio_id', $position->portfolio_id)
            ->where('asset_id', $position->asset_id)
            ->where('side', OrderSide::Sell)
            ->where('type', OrderType::Limit)
            ->where('status', OrderStatus::Pending)
            ->get();
    }

    /**
     * Book the resting sells of the position that the broker executed. A
     * simulated one fills at its own price once the quote reaches it.
     */
    public function refreshResting(Position $position, string $actor): void
    {
        foreach ($this->restingSells($position) as $order) {
            $this->settleResting($position->portfolio, $order, $this->restingState($position->portfolio, $order), $actor);
        }
    }

    /**
     * Cancel the resting sells of the position, booking what they already sold.
     * False when the broker could not confirm it, so nothing else is sent.
     */
    private function cancelResting(Position $position, string $actor): bool
    {
        $portfolio = $position->portfolio;

        foreach ($this->restingSells($position) as $order) {
            if (! $portfolio->isLive()) {
                $this->settleResting($portfolio, $order, $this->restingState($portfolio, $order) ?? new OrderResult(OrderStatus::Cancelled, $order->external_id), $actor);

                continue;
            }

            try {
                $broker = $this->brokers->for($portfolio);

                try {
                    $broker->cancelOrder((string) $order->external_id);
                } catch (LiveTradingException $e) {
                    // -2011: already executed or cancelled; the order's state below says which.
                    if ($e->binanceCode !== -2011) {
                        throw $e;
                    }
                }

                $remote = $broker->getOrder((string) $order->external_id);
            } catch (\Throwable) {
                return false;
            }

            if (! $this->settleResting($portfolio, $order, $remote, $actor)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where a resting order stands: the exchange's answer on a real portfolio,
     * a fill at the limit price on a simulated one once the quote reaches it.
     * Null when it is unknown or still resting.
     */
    private function restingState(Portfolio $portfolio, Order $order): ?OrderResult
    {
        if ($portfolio->isLive()) {
            try {
                return $this->brokers->for($portfolio)->getOrder((string) $order->external_id);
            } catch (\Throwable) {
                return null;
            }
        }

        $quote = $this->prices->quote($order->asset);
        $limit = (float) $order->price;

        if ($quote === null || $quote->price < $limit) {
            return null;
        }

        $costs = config('trading.costs.'.$order->asset->market->value);

        return new OrderResult(
            status: OrderStatus::Filled,
            externalId: $order->external_id,
            filledQty: $order->qty,
            avgPrice: $limit,
            fees: round($order->qty * $limit * $costs['fee_rate'] + ($costs['brokerage_per_order'] ?? 0), 8),
            filledAt: now(),
        );
    }

    /**
     * Book the final state of a resting order once: the fill (whole, or the
     * part sold before a cancel) reduces the position like any sale.
     *
     * @return bool false while the order is still resting
     */
    private function settleResting(Portfolio $portfolio, Order $order, ?OrderResult $result, string $actor): bool
    {
        if ($result === null || in_array($result->status, [OrderStatus::Pending, OrderStatus::PartiallyFilled], true)) {
            return false;
        }

        $booked = DB::transaction(function () use ($portfolio, $order, $result): bool {
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== OrderStatus::Pending) {
                return false;
            }

            $fresh->update([
                'status' => $result->status,
                'price' => $result->avgPrice ?? $fresh->price,
                'fees' => $result->fees,
                'reject_reason' => $result->rejectReason,
                'filled_at' => $result->filledAt,
            ]);

            if ($result->filledQty <= 0 || ! $portfolio->openPositions()->where('asset_id', $order->asset_id)->exists()) {
                return true;
            }

            $fresh->fills()->create([
                'qty' => $result->filledQty,
                'price' => $result->avgPrice,
                'fee' => $result->fees,
                'executed_at' => $result->filledAt ?? now(),
            ]);

            $request = new OrderRequest(asset: $order->asset, side: OrderSide::Sell, qty: $result->filledQty, type: OrderType::Limit, limitPrice: $order->price);
            $this->book($portfolio, $request, $result, null, $order->reason);

            return true;
        });

        if ($booked) {
            $order->refresh();
            AuditLog::record($actor, 'order.'.$order->status->value, $order, ['reason' => $order->reason, 'resting' => true, 'filled_qty' => $result->filledQty]);

            if ($portfolio->isLive()) {
                $this->alerts->order($order);
            }
        }

        return true;
    }

    /**
     * What can really be sold. On a real portfolio Binance takes the buy fee in
     * the bought coin (18.8 bought, 18.78 on the account), so the sale uses the
     * smaller of the position and the free balance; and the exchange refuses a
     * quantity off the pair's step, so it is rounded down to it.
     */
    public function sellableQty(Position $position): float
    {
        $qty = $position->qty;
        $asset = $position->asset;

        if ($position->portfolio->isLive()) {
            $coin = substr($asset->symbol, 0, -strlen($asset->quote_currency));

            try {
                $free = $this->brokers->for($position->portfolio)->getBalances()[$coin] ?? null;
            } catch (\Throwable) {
                $free = null;
            }

            if ($free !== null && $free > 0) {
                $qty = min($qty, $free);
            }
        }

        $step = (float) $asset->lot_size;

        return $step > 0 ? floor(round($qty / $step, 8)) * $step : $qty;
    }

    /**
     * Send an order that has no signal behind it (micro operations). It still
     * passes the risk guard and the broker; the caller decides whether the
     * portfolio may trade on its own.
     */
    public function placeDirect(Portfolio $portfolio, OrderRequest $request, string $actor, string $reason): Order|string
    {
        return $this->submit($portfolio, $request, null, $actor, $reason, ownerSized: true);
    }

    /**
     * Risk-check, send and book one order. Returns the order (filled or
     * rejected by the broker) or the reason the risk guard blocked it.
     */
    private function submit(Portfolio $portfolio, OrderRequest $request, ?Signal $signal, string $actor, ?string $reason, bool $ownerSized = false): Order|string
    {
        if ($portfolio->isLive()) {
            $portfolio->refresh();
        }

        $decision = $this->risk->check($portfolio, $request, $ownerSized);

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
            // Market orders carry the reference quote so the live daily cap can count them.
            'price' => $request->limitPrice ?? ($portfolio->isLive() ? $this->prices->quote($request->asset)?->price : null),
            'stop_price' => $request->stopPrice,
            'status' => OrderStatus::Pending,
            'reason' => $reason,
        ]);

        try {
            $result = $this->brokers->for($portfolio)->placeOrder($request);
        } catch (LiveTradingException $e) {
            $result = OrderResult::rejected($e->getMessage());
        }

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

        AuditLog::record($actor, 'order.'.$order->status->value, $order, [
            'decision' => $decision->reasons,
            'reason' => $reason,
            'signal' => $signal === null ? null : [
                'id' => $signal->id,
                'score' => $signal->score,
                'decided_by' => $signal->decided_by,
                'calculation' => $signal->calculation,
            ],
        ]);

        $order->refresh();

        if ($portfolio->isLive()) {
            $this->alerts->order($order);
        }

        return $order;
    }

    /**
     * A real order needs a person's click, or the automatic path the owner
     * confirmed with CONFIRMO (and that "Parar tudo" has not blocked).
     */
    private function approvedForLive(Signal $signal): bool
    {
        if ($signal->stage !== SignalStage::Confirmed) {
            return false;
        }

        return str_starts_with((string) $signal->decided_by, 'user:')
            || ($signal->decided_by === 'auto' && $signal->portfolio->autoExecutesLive());
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

        // What the step or the buy fee left behind (under 1 USDT) cannot be sold: the position is over.
        if ($remaining > 0 && $portfolio->isLive() && $remaining * $result->avgPrice < BinanceAccountSync::DUST) {
            $remaining = 0.0;
        }

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
