<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\BrokerCredential;
use App\Models\Portfolio;
use App\Trading\Contracts\Broker;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Live\BinanceAccountReader;
use App\Trading\Live\BinanceClient;
use App\Trading\Live\LiveLimits;
use App\Trading\Live\LiveTradingException;
use Illuminate\Support\Carbon;

/**
 * Live spot execution on Binance. Every call re-checks the live guard rails,
 * so a disabled switch, a halt or a withdraw-capable key stops it here too.
 *
 * External ids are stored as "SYMBOL:orderId" because Binance needs both to
 * query or cancel an order.
 */
class BinanceBroker implements Broker
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
            'type' => $order->type === OrderType::Limit ? 'LIMIT' : 'MARKET',
            'quantity' => $this->decimal($order->qty),
            'newOrderRespType' => 'FULL',
        ];

        if ($order->type === OrderType::Limit) {
            $params['price'] = $this->decimal((float) $order->limitPrice);
            $params['timeInForce'] = 'GTC';
        }

        if ($order->clientId !== null) {
            $params['newClientOrderId'] = $order->clientId;
        }

        try {
            $response = $this->send($client, $params);
        } catch (LiveTradingException $e) {
            if ($e->uncertain) {
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
     * Sends the order. Only a failure that leaves the order's fate unknown (timeout,
     * 5xx) is retried, once, and only after asking the exchange for the order by its
     * client id: the same id makes a second POST safe, but looking first avoids relying on it.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function send(BinanceClient $client, array $params): array
    {
        try {
            return $client->signed('POST', '/api/v3/order', $params);
        } catch (LiveTradingException $e) {
            if (! $e->uncertain || ! isset($params['newClientOrderId'])) {
                throw $e;
            }
        }

        try {
            return $client->signed('GET', '/api/v3/order', ['symbol' => $params['symbol'], 'origClientOrderId' => $params['newClientOrderId']]);
        } catch (LiveTradingException $lookup) {
            // -2013: the exchange never saw it, so sending again cannot duplicate it.
            if ($lookup->binanceCode !== -2013) {
                throw $lookup;
            }
        }

        return $client->signed('POST', '/api/v3/order', $params);
    }

    /**
     * Cancels every open order this app sent (client ids "signal-*" and "position-*")
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

            if (! str_starts_with($clientId, 'signal-') && ! str_starts_with($clientId, 'position-')) {
                continue;
            }

            $externalId = $remote['symbol'].':'.$remote['orderId'];

            try {
                $client->signed('DELETE', '/api/v3/order', ['symbol' => $remote['symbol'], 'orderId' => $remote['orderId']]);
                $cancelled[] = $externalId;
            } catch (LiveTradingException $e) {
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

        return $this->toResult($symbol, $this->client()->signed('GET', '/api/v3/order', ['symbol' => $symbol, 'orderId' => $orderId]));
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

        return new OrderResult(
            status: $status,
            externalId: $symbol.':'.$response['orderId'],
            filledQty: $executed,
            avgPrice: $avg,
            fees: $this->fees($symbol, $response['fills'] ?? [], $avg),
            filledAt: $executed > 0 && isset($response['transactTime']) ? Carbon::createFromTimestampMs($response['transactTime']) : ($executed > 0 ? now() : null),
            rejectReason: $status === OrderStatus::Rejected ? 'Recusada pela Binance.' : null,
        );
    }

    /**
     * Commissions in the quote asset count as is, in the base asset at the fill
     * price. Commissions in another asset (such as BNB) are not converted.
     *
     * @param  list<array<string, mixed>>  $fills
     */
    private function fees(string $symbol, array $fills, ?float $avg): float
    {
        $total = 0.0;

        foreach ($fills as $fill) {
            $asset = (string) $fill['commissionAsset'];
            $commission = (float) $fill['commission'];

            if (str_starts_with($symbol, $asset)) {
                $total += $commission * ($avg ?? (float) $fill['price']);
            } elseif (str_ends_with($symbol, $asset)) {
                $total += $commission;
            }
        }

        return round($total, 8);
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
