<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\BrokerCredential;
use App\Models\Portfolio;
use App\Trading\Contracts\Broker;
use App\Trading\Contracts\PlacesOco;
use App\Trading\Data\OcoResult;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Live\BinanceAccountReader;
use App\Trading\Live\BinanceClient;
use App\Trading\Live\LiveLimits;
use App\Trading\Live\LiveTradingException;
use App\Trading\Live\UnknownSales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live spot execution on Binance. Every call re-checks the live guard rails,
 * so a disabled switch, a halt or a withdraw-capable key stops it here too.
 *
 * External ids are stored as "SYMBOL:orderId" because Binance needs both to
 * query or cancel an order.
 */
class BinanceBroker implements Broker, PlacesOco
{
    public function __construct(
        private readonly Portfolio $portfolio,
        private readonly LiveLimits $limits,
    ) {}

    public function placeOrder(OrderRequest $order): OrderResult
    {
        $client = $this->client();
        $symbol = $order->asset->symbol;

        $params = [
            'symbol' => $symbol,
            'side' => strtoupper($order->side->value),
            'type' => match (true) {
                $order->type === OrderType::Limit && $order->postOnly => 'LIMIT_MAKER',
                $order->type === OrderType::Limit => 'LIMIT',
                default => 'MARKET',
            },
            'quantity' => $this->decimal($order->qty),
            'newOrderRespType' => 'FULL',
        ];

        if ($order->type === OrderType::Limit) {
            $params['price'] = $this->decimal((float) $order->limitPrice);

            // LIMIT_MAKER takes no time in force: it rests until it executes or is cancelled.
            if (! $order->postOnly) {
                $params['timeInForce'] = 'GTC';
            }
        }

        if ($order->clientId !== null) {
            $params['newClientOrderId'] = $order->clientId;
        }

        try {
            $response = $this->send($client, $params, $this->unknownSale($order));
        } catch (LiveTradingException $e) {
            // A request that never left cannot be on the exchange; a market sale with its own id is
            // looked up by that id before it goes out again. Neither needs the portfolio stopped (#198).
            if ($e->uncertain && ! $e->unsent && $this->unknownSale($order) === null) {
                // The order may be on the exchange without us knowing: stop this portfolio until a person looks.
                $this->portfolio->forceFill([
                    'auto_execute' => false,
                    'live_auto_confirmed_at' => null,
                    'orders_blocked_at' => now(),
                    'orders_blocked_reason' => 'Envio incerto à Binance: confira as ordens na corretora antes de liberar.',
                ])->save();
            }

            return OrderResult::rejected($e->getMessage());
        }

        return $this->toResult($symbol, $response);
    }

    /**
     * Sends a sell OCO (POST /api/v3/orderList/oco): the upper leg a
     * LIMIT_MAKER at the target, the lower a STOP_LOSS_LIMIT that triggers at
     * the stop and rests at its limit a little below. An answer lost after the
     * request left is looked up by the list's client id before sending again;
     * still unknown, the portfolio stops as for any order of unknown fate.
     */
    public function placeOco(OrderRequest $target, OrderRequest $stop, string $clientId): OcoResult
    {
        $client = $this->client();
        $symbol = $target->asset->symbol;

        $params = [
            'symbol' => $symbol,
            'side' => 'SELL',
            'quantity' => $this->decimal($target->qty),
            'listClientOrderId' => $clientId,
            'aboveType' => 'LIMIT_MAKER',
            'abovePrice' => $this->decimal((float) $target->limitPrice),
            'aboveClientOrderId' => (string) $target->clientId,
            'belowType' => 'STOP_LOSS_LIMIT',
            'belowStopPrice' => $this->decimal((float) $stop->stopPrice),
            'belowPrice' => $this->decimal((float) $stop->limitPrice),
            'belowTimeInForce' => 'GTC',
            'belowClientOrderId' => (string) $stop->clientId,
            'newOrderRespType' => 'FULL',
        ];

        try {
            $response = $this->sendOco($client, $params);
        } catch (LiveTradingException $e) {
            if ($e->uncertain && ! $e->unsent) {
                $this->portfolio->forceFill([
                    'auto_execute' => false,
                    'live_auto_confirmed_at' => null,
                    'orders_blocked_at' => now(),
                    'orders_blocked_reason' => 'Envio incerto à Binance: confira as ordens na corretora antes de liberar.',
                ])->save();
            }

            return OcoResult::rejected($e->getMessage());
        }

        $legs = [];

        foreach ($response['orderReports'] ?? [] as $report) {
            if (is_array($report) && isset($report['clientOrderId'], $report['orderId'])) {
                $legs[(string) $report['clientOrderId']] = $this->toResult($symbol, $report);
            }
        }

        $upper = $legs[(string) $target->clientId] ?? null;
        $lower = $legs[(string) $stop->clientId] ?? null;

        if ($upper === null || $lower === null || ! isset($response['orderListId'])) {
            return OcoResult::rejected('Resposta da Binance sem as duas pernas da OCO.');
        }

        return new OcoResult($upper, $lower, $symbol.':'.$response['orderListId']);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function sendOco(BinanceClient $client, array $params): array
    {
        try {
            return $client->signed('POST', '/api/v3/orderList/oco', $params);
        } catch (LiveTradingException $e) {
            if (! $e->uncertain || $e->unsent) {
                throw $e;
            }
        }

        try {
            $found = $this->lookupOco($client, $params);
        } catch (LiveTradingException $lookup) {
            throw $lookup->uncertain && ! $lookup->unsent ? $lookup : new LiveTradingException($lookup->getMessage(), 0, $lookup, $lookup->binanceCode, uncertain: true);
        }

        return $found ?? $client->signed('POST', '/api/v3/orderList/oco', $params);
    }

    /**
     * The OCO the exchange holds under the list's client id, with the state of
     * each leg as the order reports of a fresh answer; null when it never saw it.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>|null
     */
    private function lookupOco(BinanceClient $client, array $params): ?array
    {
        try {
            $list = $client->signed('GET', '/api/v3/orderList', ['origClientOrderId' => $params['listClientOrderId']]);
        } catch (LiveTradingException $e) {
            if ($e->binanceCode === -2013 || $e->binanceCode === -2011) {
                return null;
            }

            throw $e;
        }

        $reports = [];

        foreach ($list['orders'] ?? [] as $leg) {
            if (is_array($leg) && isset($leg['orderId'])) {
                $reports[] = $client->signed('GET', '/api/v3/order', ['symbol' => $params['symbol'], 'orderId' => $leg['orderId']]);
            }
        }

        return ['orderListId' => $list['orderListId'] ?? null, 'orderReports' => $reports];
    }

    /**
     * Sends the order. Only a failure that leaves the order's fate unknown (an
     * answer lost after the request left, 5xx) is retried, once, and only after
     * asking the exchange for the order by its client id: the same id makes a
     * second POST safe, but looking first avoids relying on it.
     *
     * A market sale of a position whose lookup failed too keeps its id
     * (`$saleOf`): the next attempt looks that id up before sending (#198).
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function send(BinanceClient $client, array $params, ?int $saleOf = null): array
    {
        $clientId = isset($params['newClientOrderId']) ? (string) $params['newClientOrderId'] : null;

        try {
            return $this->sendOnce($client, $params, $clientId, $saleOf);
        } catch (LiveTradingException $e) {
            if ($saleOf !== null && $clientId !== null && $e->uncertain && ! $e->unsent) {
                UnknownSales::remember($saleOf, $clientId);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function sendOnce(BinanceClient $client, array $params, ?string $clientId, ?int $saleOf): array
    {
        if ($saleOf !== null && $clientId !== null && UnknownSales::for($saleOf) === $clientId) {
            $found = $this->lookup($client, $params);
            UnknownSales::forget($saleOf);

            if ($found !== null) {
                return $found;
            }
        }

        try {
            return $client->signed('POST', '/api/v3/order', $params);
        } catch (LiveTradingException $e) {
            if (! $e->uncertain || $e->unsent || $clientId === null) {
                throw $e;
            }
        }

        try {
            $found = $this->lookup($client, $params);
        } catch (LiveTradingException $lookup) {
            // The order already left: whatever stopped the lookup, its fate stays unknown.
            throw $lookup->uncertain && ! $lookup->unsent ? $lookup : new LiveTradingException($lookup->getMessage(), 0, $lookup, $lookup->binanceCode, uncertain: true);
        }

        return $found ?? $client->signed('POST', '/api/v3/order', $params);
    }

    /**
     * The order the exchange holds under the client id, or null when it never
     * saw it (-2013), so sending it cannot duplicate it.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>|null
     */
    private function lookup(BinanceClient $client, array $params): ?array
    {
        try {
            return $client->signed('GET', '/api/v3/order', ['symbol' => $params['symbol'], 'origClientOrderId' => $params['newClientOrderId']]);
        } catch (LiveTradingException $e) {
            if ($e->binanceCode === -2013) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The position whose market sale this is, when the sale carries its own
     * id: only those are safe to settle by looking the id up later.
     */
    private function unknownSale(OrderRequest $order): ?int
    {
        return $order->side === OrderSide::Sell && $order->type === OrderType::Market && $order->clientId !== null
            ? $order->positionId
            : null;
    }

    /**
     * Cancels every open order this app sent (client ids "signal-*", "position-*" and the
     * micro operations' limit buys "micro-*")
     * and returns how many were cancelled. Orders placed by hand on the exchange stay.
     * It works even while the portfolio is blocked, halted or live execution is off:
     * cancelling never moves money.
     *
     * @return array{cancelled: list<string>, failed: list<string>}
     */
    public function cancelOpenOrders(): array
    {
        $client = $this->readClient();
        $cancelled = [];
        $failed = [];

        /** @var list<array<string, mixed>> $open */
        $open = $client->signed('GET', '/api/v3/openOrders');

        foreach ($open as $remote) {
            $clientId = (string) ($remote['clientOrderId'] ?? '');

            if (! str_starts_with($clientId, 'signal-') && ! str_starts_with($clientId, 'position-') && ! str_starts_with($clientId, 'micro-')) {
                continue;
            }

            $externalId = $remote['symbol'].':'.$remote['orderId'];

            try {
                $client->signed('DELETE', '/api/v3/order', ['symbol' => $remote['symbol'], 'orderId' => $remote['orderId']]);
                $cancelled[] = $externalId;
            } catch (LiveTradingException $e) {
                // -2011: the other leg of an OCO just cancelled went with it.
                if ($e->binanceCode === -2011) {
                    $cancelled[] = $externalId;

                    continue;
                }

                $failed[] = $externalId.': '.$e->getMessage();
            }
        }

        return ['cancelled' => $cancelled, 'failed' => $failed];
    }

    public function cancelOrder(string $externalId): void
    {
        [$symbol, $orderId] = $this->split($externalId);

        $this->client()->signed('DELETE', '/api/v3/order', ['symbol' => $symbol, 'orderId' => $orderId]);
    }

    public function getOrder(string $externalId): OrderResult
    {
        [$symbol, $orderId] = $this->split($externalId);

        // Reading an order moves no money: the reconciler and the resting sells still see it during "Parada geral".
        $client = $this->readClient();
        $response = $client->signed('GET', '/api/v3/order', ['symbol' => $symbol, 'orderId' => $orderId]);

        // The order query carries no fills: the commission of what executed is in the order's trades.
        if (! isset($response['fills']) && (float) ($response['executedQty'] ?? 0) > 0) {
            $response['fills'] = $this->trades($client, $symbol, $orderId);
        }

        return $this->toResult($symbol, $response);
    }

    /**
     * Trades of one order, with their commission. Empty when they cannot be
     * read, so the fee falls back to the configured rate.
     *
     * @return list<array<string, mixed>>
     */
    public function trades(BinanceClient $client, string $symbol, string $orderId): array
    {
        try {
            $rows = $client->signed('GET', '/api/v3/myTrades', ['symbol' => $symbol, 'orderId' => $orderId]);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter($rows, fn ($row) => is_array($row) && isset($row['commission'], $row['commissionAsset'], $row['price'], $row['qty'])));
    }

    /**
     * Reads the commission of an executed order already in the app.
     */
    public function orderFees(string $externalId): ?float
    {
        [$symbol, $orderId] = $this->split($externalId);
        $trades = $this->trades($this->readClient(), $symbol, $orderId);

        return $trades === [] ? null : $this->fees($symbol, $trades, null, 0.0)[0];
    }

    public function getBalances(): array
    {
        return (new BinanceAccountReader)->balances($this->readClient(), locked: false);
    }

    public function supports(Market $market): bool
    {
        return $market === Market::Crypto;
    }

    private function client(): BinanceClient
    {
        $credential = $this->limits->assertCanSend($this->portfolio, $this->portfolio->brokerCredential);

        return new BinanceClient($this->assertOwnCredential($credential));
    }

    /**
     * Reading the account does not move money, so it does not need the live
     * switch; it still needs a verified key that belongs to the portfolio's owner.
     */
    private function readClient(): BinanceClient
    {
        $credential = $this->portfolio->brokerCredential;

        if ($credential === null || $credential->can_withdraw || $credential->verified_at === null) {
            throw new LiveTradingException('Carteira sem chave da Binance verificada e sem permissão de saque.');
        }

        return new BinanceClient($this->assertOwnCredential($credential));
    }

    private function assertOwnCredential(BrokerCredential $credential): BrokerCredential
    {
        if ($credential->broker !== 'binance') {
            throw new LiveTradingException('A credencial da carteira não é da Binance.');
        }

        if ($credential->user_id !== $this->portfolio->user_id) {
            throw new LiveTradingException('A credencial não pertence ao dono da carteira.');
        }

        return $credential;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function toResult(string $symbol, array $response): OrderResult
    {
        $status = match ($response['status'] ?? '') {
            'FILLED' => OrderStatus::Filled,
            'PARTIALLY_FILLED' => OrderStatus::PartiallyFilled,
            'CANCELED', 'EXPIRED' => OrderStatus::Cancelled,
            'REJECTED' => OrderStatus::Rejected,
            default => OrderStatus::Pending,
        };

        $executed = (float) ($response['executedQty'] ?? 0);
        $avg = $executed > 0 ? (float) $response['cummulativeQuoteQty'] / $executed : null;
        [$fees, $feeAsset] = $this->fees($symbol, $response['fills'] ?? [], $avg, $executed);

        return new OrderResult(
            status: $status,
            externalId: $symbol.':'.$response['orderId'],
            filledQty: $executed,
            avgPrice: $avg,
            fees: $fees,
            feeAsset: $feeAsset,
            filledAt: $executed > 0 && isset($response['transactTime']) ? Carbon::createFromTimestampMs($response['transactTime']) : ($executed > 0 ? now() : null),
            rejectReason: $status === OrderStatus::Rejected ? 'Recusada pela Binance.' : null,
        );
    }

    /**
     * Commissions in the quote asset count as is, in the base asset at the fill
     * price and in another asset (such as BNB) at its USDT price. A fill whose
     * commission cannot be priced, or an executed order with no fills at all,
     * is charged the configured rate: never zero. A commission in BNB that
     * cannot be priced gets the rate with the BNB discount.
     * Also returns the asset that paid the commission (null without fills).
     *
     * @param  list<array<string, mixed>>  $fills
     * @return array{0: float, 1: ?string}
     */
    private function fees(string $symbol, array $fills, ?float $avg, float $executed): array
    {
        $rate = (float) config('trading.costs.crypto.fee_rate');

        if ($fills === []) {
            return [round($executed * ($avg ?? 0) * $rate, 8), null];
        }

        $total = 0.0;
        $paid = [];

        foreach ($fills as $fill) {
            $asset = (string) $fill['commissionAsset'];
            $commission = (float) $fill['commission'];
            $price = (float) $fill['price'];
            $paid[$asset] = ($paid[$asset] ?? 0) + 1;

            if (str_starts_with($symbol, $asset)) {
                $total += $commission * ($avg ?? $price);
            } elseif (str_ends_with($symbol, $asset)) {
                $total += $commission;
            } else {
                $usdt = $commission > 0 ? $this->usdtPrice($asset) : 0.0;
                $fallback = $asset === 'BNB' ? $rate * (1 - (float) config('trading.costs.crypto.bnb_discount')) : $rate;
                $total += $usdt === null ? (float) $fill['qty'] * $price * $fallback : $commission * $usdt;
            }
        }

        arsort($paid);

        return [round($total, 8), (string) array_key_first($paid)];
    }

    /**
     * Last USDT price of a coin, from the public endpoint; null when unknown.
     * Kept for a minute: every fill of every order paid in BNB asks for it.
     */
    private function usdtPrice(string $asset): ?float
    {
        $cached = Cache::get('binance:usdt-price:'.$asset);

        if (is_float($cached)) {
            return $cached;
        }

        try {
            $price = Http::baseUrl(config('trading.binance.base_url'))->timeout(10)
                ->get('/api/v3/ticker/price', ['symbol' => $asset.'USDT'])->throw()->json('price');
        } catch (Throwable) {
            return null;
        }

        if (! is_numeric($price) || (float) $price <= 0) {
            return null;
        }

        Cache::put('binance:usdt-price:'.$asset, (float) $price, now()->addMinute());

        return (float) $price;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $externalId): array
    {
        $parts = explode(':', $externalId, 2);

        if (count($parts) !== 2) {
            throw new LiveTradingException("Id externo inválido: {$externalId}.");
        }

        return [$parts[0], $parts[1]];
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }
}
