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
use App\Trading\Contracts\PlacesOco;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\OcoResult;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\LiveAlerts;
use App\Trading\Live\LiveTradingException;
use App\Trading\Live\UnknownSales;
use App\Trading\Risk\RiskGuard;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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

        $positionId = null;

        // A sell signal sells the position like an exit: the pair's minimum is checked first, a resting
        // limit sale is cancelled so the coins are free, and the quantity stays on the pair's step.
        if ($signal->side === OrderSide::Sell) {
            $position = $signal->portfolio->openPositions()->with(['asset', 'portfolio'])->where('asset_id', $signal->asset_id)->oldest('id')->first();
            $reason = match (true) {
                $position === null => 'Sem posição aberta para vender.',
                ($small = $this->belowMinimum($position, $this->prices->quote($signal->asset)?->price)) !== null => $small,
                ! $this->cancelResting($position, $actor) => 'A venda limitada aberta não pôde ser cancelada na corretora.',
                $position->refresh()->closed_at !== null => 'A venda registrada da posição já executou.',
                default => null,
            };

            if ($reason !== null) {
                $signal->moveTo(SignalStage::Rejected, $reason);
                AuditLog::record($actor, 'order.blocked', $signal, ['reasons' => [$reason]]);

                return null;
            }

            $positionId = $position->id;
            $request = new OrderRequest(asset: $request->asset, side: OrderSide::Sell, qty: $this->sellableQty($position), stopPrice: $request->stopPrice, clientId: $request->clientId, positionId: $positionId);
        }

        $outcome = $this->submit($signal->portfolio, $request, $signal, $actor, null, positionId: $positionId);

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
    public function closePosition(Position $position, string $reason, string $actor, ?string &$blocked = null): ?Order
    {
        // Below the pair's minimum the exchange refuses a market sale: nothing is cancelled nor sent.
        if (($blocked = $this->belowMinimum($position, $this->prices->quote($position->asset)?->price)) !== null) {
            // The detail carries the price and changes every minute: the code keeps it to one log an hour.
            $this->logCloseBlocked($position, $actor, ['reason' => $reason, 'code' => 'below_minimum', 'detail' => $blocked]);

            return null;
        }

        // A resting limit sell locks the coins: it goes first, and what it already sold counts.
        if (! $this->cancelResting($position, $actor)) {
            $blocked = 'A venda limitada aberta não pôde ser cancelada na corretora.';
            $this->logCloseBlocked($position, $actor, ['reason' => $reason, 'detail' => $blocked]);

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

        // An earlier sale of the position whose fate the exchange did not tell goes out under its own id,
        // so the broker looks it up first; the coins it may have sold no longer show in the balance (#198).
        $unknown = $position->portfolio->isLive() ? UnknownSales::for($position->id) : null;
        $step = (float) $position->asset->lot_size;
        $qty = $unknown === null ? $this->sellableQty($position) : ($step > 0 ? floor(round($position->qty / $step, 8)) * $step : $position->qty);

        // What really goes out may be under the minimum even when the whole position was not (fee
        // taken in the coin, step): the exchange would refuse it, so it is not sent (#200).
        if (($blocked = $this->belowMinimum($position, $this->prices->quote($position->asset)?->price, $qty)) !== null) {
            $this->logCloseBlocked($position, $actor, ['reason' => $reason, 'code' => 'below_minimum', 'detail' => $blocked]);

            return null;
        }

        $request = new OrderRequest(
            asset: $position->asset,
            side: OrderSide::Sell,
            qty: $qty,
            clientId: $unknown ?? 'position-'.$position->id.'-'.$reason,
            positionId: $position->id,
        );

        $outcome = $this->submit($position->portfolio, $request, $position->signal, $actor, $reason, positionId: $position->id);

        if (is_string($outcome)) {
            $blocked = $outcome;
            $this->logCloseBlocked($position, $actor, ['reason' => $reason, 'detail' => $outcome]);

            return null;
        }

        return $outcome;
    }

    /**
     * The exits job retries every minute; log the same block once an hour, not once a minute.
     * A position under the pair's minimum stays stuck until the price comes back, whatever
     * exit tries it (stop, time limit): that block is logged once for the position (#208).
     *
     * @param  array<string, string>  $payload  with a `code`, the same reason and code count as the same block
     */
    private function logCloseBlocked(Position $position, string $actor, array $payload): void
    {
        $code = $payload['code'] ?? null;
        $same = fn (array $logged): bool => match (true) {
            $code === 'below_minimum' => ($logged['code'] ?? null) === $code,
            $code !== null => ($logged['reason'] ?? null) === $payload['reason'] && ($logged['code'] ?? null) === $code,
            default => $logged === $payload,
        };

        $repeated = AuditLog::query()
            ->where('action', 'position.close_blocked')
            ->where('subject_type', $position->getMorphClass())
            ->where('subject_id', $position->getKey())
            ->when($code !== 'below_minimum', fn ($query) => $query->where('created_at', '>=', now()->subHour()))
            ->get()
            ->contains(fn (AuditLog $log) => is_array($log->payload) && $same($log->payload));

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
            // A new attempt after a refusal needs its own id at the exchange.
            clientId: 'position-'.$position->id.'-'.$reason.'-'.now()->format('dHi'),
            positionId: $position->id,
        );

        return $this->submit($position->portfolio, $request, null, $actor, $reason, positionId: $position->id);
    }

    /**
     * Leave a sell OCO of the whole position at the exchange (micro operations
     * with "Stop na corretora"): a limit at the target and a stop-limit that
     * triggers at `$stop` and rests a little below it, so the exchange sells
     * at the stop with no wait for the app. Both legs pass the risk guard as
     * one sale and are booked as two resting orders of the same group.
     *
     * @return array{target: Order, stop: Order}|string the two legs, or why the OCO did not go out
     */
    public function placeRestingOco(Position $position, float $target, float $stop, string $actor): array|string
    {
        $portfolio = $position->portfolio;
        $asset = $position->asset;
        $broker = $this->brokers->for($portfolio);

        if (! $broker instanceof PlacesOco) {
            return 'A corretora da carteira não aceita ordem OCO.';
        }

        $tick = (float) $asset->tick_size;
        $round = fn (float $price, callable $how): float => $tick > 0 ? round($how(round($price / $tick, 8)) * $tick, 8) : $price;
        $upper = $round($target, 'ceil');
        $trigger = $round($stop, 'round');
        // Under the trigger by the configured gap: the sale still executes when the price falls fast.
        $limit = $round($trigger * (1 - (float) config('trading.micro.oco.limit_gap_pct') / 100), 'floor');
        $qty = $this->sellableQty($position);

        if ($qty <= 0 || $limit <= 0) {
            return 'Quantidade vendável zerada pelo passo do par.';
        }

        // Each leg is an order of its own at the exchange: the stop's, at its limit, must reach the minimum too.
        $minimum = $portfolio->isLive() ? (float) $asset->min_notional : 0.0;

        if ($qty * $limit < $minimum) {
            return sprintf('A perna de stop da OCO (%s a %s) vale %.2f USDT, abaixo do mínimo de %.2f USDT do par.', $qty, $limit, $qty * $limit, $minimum);
        }

        $stamp = now()->format('dHis');
        $sale = new OrderRequest(asset: $asset, side: OrderSide::Sell, qty: $qty, type: OrderType::Limit, limitPrice: $upper, clientId: 'position-'.$position->id.'-oco-t-'.$stamp, positionId: $position->id);

        if ($portfolio->isLive()) {
            $portfolio->refresh();
        }

        $decision = $this->risk->check($portfolio, $sale, true);

        if (! $decision->allowed) {
            AuditLog::record($actor, 'order.blocked', null, ['reasons' => $decision->reasons]);

            return 'Guarda de risco: '.$decision->summary();
        }

        $sale = $decision->order;
        $stopSale = new OrderRequest(asset: $asset, side: OrderSide::Sell, qty: $sale->qty, type: OrderType::Limit, limitPrice: $limit, stopPrice: $trigger, clientId: 'position-'.$position->id.'-oco-s-'.$stamp, positionId: $position->id);

        $legs = [];

        foreach (['target' => $sale, 'stop' => $stopSale] as $reason => $request) {
            $legs[$reason] = Order::create([
                'portfolio_id' => $portfolio->id,
                'asset_id' => $asset->id,
                'position_id' => $position->id,
                'broker' => $portfolio->broker,
                'side' => OrderSide::Sell,
                'type' => OrderType::Limit,
                'qty' => $request->qty,
                'price' => $request->limitPrice,
                'stop_price' => $request->stopPrice,
                'status' => OrderStatus::Pending,
                'reason' => $reason,
            ]);
        }

        try {
            $result = $broker->placeOco($sale, $stopSale, 'position-'.$position->id.'-oco-'.$stamp);
        } catch (LiveTradingException $e) {
            $result = OcoResult::rejected($e->getMessage());
        }

        $accepted = $result->accepted() && $result->target->status !== OrderStatus::Rejected && $result->stop->status !== OrderStatus::Rejected;

        foreach (['target' => $result->target, 'stop' => $result->stop] as $reason => $leg) {
            $legs[$reason]->update([
                'external_id' => $leg->externalId,
                'oco_group' => $result->group,
                'status' => $accepted ? OrderStatus::Pending : OrderStatus::Rejected,
                'reject_reason' => $accepted ? null : ($leg->rejectReason ?? $result->target->rejectReason ?? 'Recusada pela corretora.'),
            ]);
            AuditLog::record($actor, 'order.'.$legs[$reason]->status->value, $legs[$reason], ['decision' => $decision->reasons, 'reason' => $reason, 'oco' => $result->group]);
        }

        if (! $accepted) {
            return 'corretora recusou a OCO: '.($result->target->rejectReason ?? $result->stop->rejectReason ?? 'sem motivo informado');
        }

        if ($portfolio->isLive()) {
            $this->alerts->order($legs['target']);
        }

        return $legs;
    }

    /**
     * The stop leg of the position's OCO still resting at the exchange, or null.
     */
    public function restingOcoStop(Position $position): ?Order
    {
        return $this->restingSells($position)->first(fn (Order $order) => $order->isOcoStop());
    }

    /**
     * Limit sells of the position still resting at the broker (and older ones
     * that never recorded their position).
     *
     * @return Collection<int, Order>
     */
    public function restingSells(Position $position): Collection
    {
        return $this->pendingSells($position)
            ->where(fn ($query) => $query->whereNull('position_id')->orWhere('position_id', $position->id))
            ->get();
    }

    /**
     * Every limit sell of the pair still resting, including those of a position
     * that already closed (a cycle the account sync ended while its sale rested).
     *
     * @return Builder<Order>
     */
    private function pendingSells(Position $position): Builder
    {
        return Order::query()
            ->with('asset')
            ->where('portfolio_id', $position->portfolio_id)
            ->where('asset_id', $position->asset_id)
            ->where('side', OrderSide::Sell)
            ->where('type', OrderType::Limit)
            ->where('status', OrderStatus::Pending);
    }

    /**
     * Book the resting sells of the position that the broker executed. A
     * simulated one fills at its own price once the quote reaches it.
     */
    public function refreshResting(Position $position, string $actor): void
    {
        foreach ($this->pendingSells($position)->get() as $order) {
            $this->settleResting($position->portfolio, $order, $this->restingState($position->portfolio, $order), $actor);
        }
    }

    /**
     * Book every resting sell of the portfolio that the broker already executed
     * or cancelled, whatever the pair or the position. Reading the order moves
     * no money, so it also runs during "Parada geral".
     *
     * @return int how many reached a final state
     */
    public function refreshPortfolio(Portfolio $portfolio, string $actor): int
    {
        $settled = 0;

        $orders = Order::query()
            ->with('asset')
            ->where('portfolio_id', $portfolio->id)
            ->where('side', OrderSide::Sell)
            ->where('type', OrderType::Limit)
            ->where('status', OrderStatus::Pending)
            ->whereNotNull('external_id')
            ->get();

        foreach ($orders as $order) {
            if ($this->settleResting($portfolio, $order, $this->restingState($portfolio, $order), $actor)) {
                $settled++;
            }
        }

        return $settled;
    }

    /**
     * Book a resting limit buy (the micro operations entry) once the broker
     * executed it or it reached another final state. Reading the order moves
     * no money. False while it still rests.
     */
    public function refreshRestingBuy(Order $order, string $actor): bool
    {
        $order->loadMissing(['asset', 'portfolio']);

        if ($order->status !== OrderStatus::Pending) {
            return true;
        }

        return $this->settleResting($order->portfolio, $order, $this->restingState($order->portfolio, $order), $actor);
    }

    /**
     * Cancel a resting limit buy, booking what it already bought (a partial
     * fill becomes a position like any buy). False when the broker could not
     * confirm it, so the caller tries again.
     */
    public function cancelRestingBuy(Order $order, string $actor): bool
    {
        $order->loadMissing(['asset', 'portfolio']);
        $portfolio = $order->portfolio;

        if ($order->status !== OrderStatus::Pending) {
            return true;
        }

        if (! $portfolio->isLive()) {
            return $this->settleResting($portfolio, $order, $this->restingState($portfolio, $order) ?? new OrderResult(OrderStatus::Cancelled, $order->external_id), $actor);
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

        return $this->settleResting($portfolio, $order, $remote, $actor);
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
     * a fill at the limit price on a simulated one once the quote reaches it
     * (at or above the limit for a sale, at or below it for a buy).
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

        // The stop leg of an OCO triggers at its stop and sells at the price of then, never under its limit:
        // a price that fell past the limit leaves it resting, as on the exchange, and the app's stop sells at market.
        if ($order->isOcoStop()) {
            if ($quote === null || $quote->price > (float) $order->stop_price || $quote->price < $limit) {
                return null;
            }

            $limit = $quote->price;
        } elseif ($quote === null || ($order->side === OrderSide::Buy ? $quote->price > $limit : $quote->price < $limit)) {
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

            if ($result->filledQty <= 0) {
                return true;
            }

            $fresh->fills()->create([
                'qty' => $result->filledQty,
                'price' => $result->avgPrice,
                'fee' => $result->fees,
                'executed_at' => $result->filledAt ?? now(),
            ]);

            $request = new OrderRequest(asset: $order->asset, side: $order->side, qty: $result->filledQty, type: OrderType::Limit, limitPrice: $order->price, stopPrice: $order->stop_price);
            $this->book($portfolio, $request, $result, null, $order->reason, $fresh->position_id);

            // One leg of a simulated OCO executed: the other goes, as the exchange expires it on a real one.
            if ($fresh->oco_group !== null && ! $portfolio->isLive()) {
                Order::query()->where('oco_group', $fresh->oco_group)->whereKeyNot($fresh->id)->where('status', OrderStatus::Pending)
                    ->update(['status' => OrderStatus::Cancelled]);
            }

            return true;
        });

        if ($booked) {
            $order->refresh();
            AuditLog::record($actor, 'order.'.$order->status->value, $order, ['reason' => $order->reason, 'resting' => true, 'filled_qty' => $result->filledQty]);

            // A limit buy that expired with nothing bought changed nothing: no alert for it.
            if ($portfolio->isLive() && ($order->side === OrderSide::Sell || $result->filledQty > 0)) {
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

            // Without the balance, the fee the exchange may have taken in the coin is left out (#200).
            $qty = $free !== null && $free > 0
                ? min($qty, $free)
                : $qty * (1 - (float) config('trading.costs.crypto.fee_rate'));
        }

        $step = (float) $asset->lot_size;

        return $step > 0 ? floor(round($qty / $step, 8)) * $step : $qty;
    }

    /**
     * Why a market sale of the whole position would be refused by the exchange
     * for being under the pair's minimum order value, or null. Counts only the
     * whole steps of what can be sold: the given quantity, else the resting
     * sale's (already net of the fee taken in the coin), else the position.
     */
    public function belowMinimum(Position $position, ?float $price, ?float $qty = null): ?string
    {
        $asset = $position->asset;
        $minimum = $position->portfolio->isLive() ? (float) $asset->min_notional : 0.0;

        if ($price === null || $minimum <= 0) {
            return null;
        }

        if ($qty === null) {
            // The two legs of an OCO sell the same coins: they count once.
            $resting = (float) $this->restingSells($position)->reject(fn (Order $order) => $order->isOcoStop())->sum('qty');
            $qty = $resting > 0 ? min($position->qty, $resting) : $position->qty;
        }

        $step = (float) $asset->lot_size;
        $qty = $step > 0 ? floor(round($qty / $step, 8)) * $step : $qty;

        return $qty * $price < $minimum
            ? sprintf('A venda de %s %s a %s vale %.2f USDT, abaixo do mínimo de %.2f USDT do par: a corretora não aceita.', $qty, $asset->symbol, $price, $qty * $price, $minimum)
            : null;
    }

    /**
     * When the exit of the position was first refused for being under the pair's minimum.
     */
    public function stuckSince(Position $position): ?CarbonInterface
    {
        return AuditLog::query()
            ->where('action', 'position.close_blocked')
            ->where('subject_type', $position->getMorphClass())
            ->where('subject_id', $position->getKey())
            ->oldest('id')
            ->get()
            ->first(fn (AuditLog $log) => ($log->payload['code'] ?? null) === 'below_minimum')
            ?->created_at;
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
    private function submit(Portfolio $portfolio, OrderRequest $request, ?Signal $signal, string $actor, ?string $reason, bool $ownerSized = false, ?int $positionId = null): Order|string
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
            'position_id' => $positionId,
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

        DB::transaction(function () use ($order, $result, $signal, $portfolio, $request, $reason, $positionId) {
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

            $this->book($portfolio, $request, $result, $signal, $reason, $positionId);
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

        // A resting limit buy (micro operations) is told when it executes, not each time it is placed.
        if ($portfolio->isLive() && ! ($order->side === OrderSide::Buy && $order->status === OrderStatus::Pending)) {
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

    /**
     * A sell that names its position only reduces that one: the sale of a
     * position already closed brings the cash in and leaves the open one alone
     * (on a real portfolio the account sync fixes the quantity).
     */
    private function book(Portfolio $portfolio, OrderRequest $request, OrderResult $result, ?Signal $signal, ?string $reason, ?int $positionId = null): void
    {
        $value = $result->filledQty * $result->avgPrice;

        $position = $portfolio->openPositions()
            ->where('asset_id', $request->asset->id)
            ->when($request->side === OrderSide::Sell && $positionId !== null, fn ($query) => $query->whereKey($positionId))
            ->lockForUpdate()
            ->first();

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

        if ($position === null) {
            return;
        }

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
