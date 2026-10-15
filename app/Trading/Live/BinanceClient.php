<?php

namespace App\Trading\Live;

use App\Models\BrokerCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Minimal signed REST client for the Binance spot API.
 */
class BinanceClient
{
    public function __construct(private readonly BrokerCredential $credential) {}

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    public function signed(string $method, string $path, array $params = []): array
    {
        $params['timestamp'] = (int) (microtime(true) * 1000);
        $params['recvWindow'] = config('trading.binance.recv_window');

        $query = http_build_query($params);
        $query .= '&signature='.hash_hmac('sha256', $query, $this->credential->api_secret);

        /** @var array<string, mixed> $json */
        $json = $this->http()->send($method, $path.'?'.$query)->throw()->json();

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('trading.binance.base_url'))
            ->withHeaders(['X-MBX-APIKEY' => $this->credential->api_key])
            ->timeout(10);
    }
}
