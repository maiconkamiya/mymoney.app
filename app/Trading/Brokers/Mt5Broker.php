<?php

namespace App\Trading\Brokers;

use App\Enums\Market;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Portfolio;
use App\Trading\Contracts\Broker;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Live\LiveLimits;
use App\Trading\Live\LiveTradingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Live B3 execution through MetaTrader 5. MT5 exposes no REST API, so this
 * talks to a small gateway running next to the terminal (config trading.mt5):
 *
 *   POST   /orders         {login, symbol, side, type, volume, price?, client_id?}
 *   GET    /orders/{ticket}
 *   DELETE /orders/{ticket}
 *   GET    /account?login=
 *
 * Order replies carry: ticket, status, filled_volume, avg_price, fee, time, reason.
 */
class Mt5Broker implements Broker
{
    public function __construct(
        private readonly Portfolio $portfolio,
        private readonly LiveLimits $limits,
    ) {}

    public function placeOrder(OrderRequest $order): OrderResult
    {
        $login = $this->guard();

        try {
            $response = $this->http()->post('/orders', array_filter([
                'login' => $login,
                'symbol' => $order->asset->symbol,
                'side' => $order->side->value,
                'type' => $order->type === OrderType::Limit ? 'limit' : 'market',
                'volume' => $order->qty,
                'price' => $order->limitPrice,
                'client_id' => $order->clientId,
            ], fn ($value) => $value !== null))->throw()->json();
        } catch (RequestException $e) {
            return OrderResult::rejected('MT5: '.($e->response->json('reason') ?? $e->getMessage()));
        }

        return $this->toResult($response);
    }

    public function cancelOrder(string $externalId): void
    {
        $this->guard();

        $this->http()->delete('/orders/'.$externalId)->throw();
    }

    public function getOrder(string $externalId): OrderResult
    {
        $this->guard();

        return $this->toResult($this->http()->get('/orders/'.$externalId)->throw()->json());
    }

    public function getBalances(): array
    {
        $login = $this->guard();

        $account = $this->http()->get('/account', ['login' => $login])->throw()->json();

        return [($account['currency'] ?? 'BRL') => (float) ($account['balance'] ?? 0)];
    }

    public function supports(Market $market): bool
    {
        return $market === Market::B3;
    }

    private function guard(): string
    {
        $credential = $this->limits->assertCanSend($this->portfolio, $this->portfolio->brokerCredential);

        if ($credential->broker !== 'mt5') {
            throw new LiveTradingException('A credencial da carteira não é do MT5.');
        }

        return $credential->api_key;
    }

    private function http(): PendingRequest
    {
        $url = config('trading.mt5.gateway_url');

        if (blank($url)) {
            throw new LiveTradingException('MT5_GATEWAY_URL não configurado.');
        }

        return Http::baseUrl($url)->withToken((string) config('trading.mt5.gateway_token'))->timeout(10);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function toResult(array $response): OrderResult
    {
        $status = match ($response['status'] ?? '') {
            'filled' => OrderStatus::Filled,
            'partially_filled' => OrderStatus::PartiallyFilled,
            'cancelled' => OrderStatus::Cancelled,
            'rejected' => OrderStatus::Rejected,
            default => OrderStatus::Pending,
        };

        $filled = (float) ($response['filled_volume'] ?? 0);

        return new OrderResult(
            status: $status,
            externalId: isset($response['ticket']) ? (string) $response['ticket'] : null,
            filledQty: $filled,
            avgPrice: $filled > 0 ? (float) $response['avg_price'] : null,
            fees: (float) ($response['fee'] ?? 0),
            filledAt: $filled > 0 ? (isset($response['time']) ? Carbon::parse($response['time']) : now()) : null,
            rejectReason: $response['reason'] ?? null,
        );
    }
}
