<?php

namespace App\Trading\Gateways;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\BrokerCredential;
use App\Trading\Data\OrderRequest;
use App\Trading\Data\OrderResult;
use App\Trading\Live\LiveTradingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * MT5 has no REST API, so this talks to a small gateway running next to the
 * terminal (config trading.mt5):
 *
 *   POST   /orders         {login, symbol, side, type, volume, price?, client_id?}
 *   GET    /orders/{ticket}
 *   DELETE /orders/{ticket}
 *   GET    /account?login=
 *
 * Order replies carry: ticket, status, filled_volume, avg_price, fee, time, reason.
 * Without a credential bound by `using()` every call refuses.
 */
class Mt5HttpGateway implements LiveGateway
{
    private ?BrokerCredential $credential = null;

    public function using(BrokerCredential $credential): static
    {
        $clone = clone $this;
        $clone->credential = $credential;

        return $clone;
    }

    public function send(OrderRequest $order): OrderResult
    {
        try {
            $response = $this->http()->post('/orders', array_filter([
                'login' => $this->login(),
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

    public function cancel(string $externalId): void
    {
        $this->http()->delete('/orders/'.$externalId)->throw();
    }

    public function fetch(string $externalId): OrderResult
    {
        return $this->toResult($this->http()->get('/orders/'.$externalId)->throw()->json());
    }

    public function balances(): array
    {
        $account = $this->http()->get('/account', ['login' => $this->login()])->throw()->json();

        return [($account['currency'] ?? 'BRL') => (float) ($account['balance'] ?? 0)];
    }

    private function login(): string
    {
        return $this->credential->api_key ?? throw new LiveTradingException('Gateway MT5 sem credencial.');
    }

    private function http(): PendingRequest
    {
        $this->login();

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
