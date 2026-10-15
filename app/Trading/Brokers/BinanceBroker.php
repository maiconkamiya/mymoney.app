<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Portfolio;
use App\Trading\Contracts\Broker;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Live\BinanceClient;
use App\Trading\Live\LiveLimits;
use App\Trading\Live\LiveTradingException;
use Illuminate\Http\Client\RequestException;
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
            $response = $client->signed('POST', '/api/v3/order', $params);
        } catch (RequestException $e) {
            return OrderResult::rejected('Binance: '.($e->response->json('msg') ?? $e->getMessage()));
        }

        return $this->toResult($symbol, $response);
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
        $account = $this->client()->signed('GET', '/api/v3/account');
        $balances = [];

        foreach ($account['balances'] ?? [] as $balance) {
            $free = (float) $balance['free'];

            if ($free > 0) {
                $balances[$balance['asset']] = $free;
            }
        }

        return $balances;
    }

    public function supports(Market $market): bool
    {
        return $market === Market::Crypto;
    }

    private function client(): BinanceClient
    {
        $credential = $this->limits->assertCanSend($this->portfolio, $this->portfolio->brokerCredential);

        if ($credential->broker !== 'binance') {
            throw new LiveTradingException('A credencial da carteira não é da Binance.');
        }

        return new BinanceClient($credential);
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
