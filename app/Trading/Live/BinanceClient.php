<?php

namespace App\Trading\Live;

use App\Models\BrokerCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal signed REST client for the Binance spot API.
 */
class BinanceClient
{
    public function __construct(private readonly BrokerCredential $credential) {}

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     *
     * @throws LiveTradingException on geoblocking (451), connection failures and rejected keys
     */
    public function signed(string $method, string $path, array $params = []): array
    {
        $params['timestamp'] = (int) (microtime(true) * 1000);
        $params['recvWindow'] = config('trading.binance.recv_window');

        $query = http_build_query($params);
        $query .= '&signature='.hash_hmac('sha256', $query, $this->credential->api_secret);

        try {
            /** @var array<string, mixed> $json */
            $json = $this->http()->send($method, $path.'?'.$query)->throw()->json();
        } catch (ConnectionException $e) {
            throw new LiveTradingException('Sem conexão com a Binance: '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            throw $this->translate($e);
        }

        return $json;
    }

    /**
     * Turns an HTTP failure into a message a person can act on. Keys and
     * signatures never reach the log: only the status and Binance's own text.
     */
    private function translate(RequestException $e): LiveTradingException
    {
        $status = $e->response->status();
        $message = (string) $e->response->json('msg', '');
        $base = (string) config('trading.binance.base_url');

        $text = match (true) {
            $status === 451 => "A Binance bloqueou este servidor por região (451) em {$base}. Use um servidor em região liberada ou ajuste BINANCE_API_URL.",
            $status === 401 || $status === 403 => 'A Binance recusou a chave (verifique permissões e a restrição de IP)'.($message !== '' ? ": {$message}" : '.'),
            $status === 418 || $status === 429 => 'A Binance limitou as chamadas deste servidor. Tente de novo em alguns minutos.',
            default => "Erro da Binance ({$status})".($message !== '' ? ": {$message}" : '.'),
        };

        Log::warning('binance.request_failed', ['status' => $status, 'base_url' => $base, 'code' => $e->response->json('code')]);

        return new LiveTradingException($text, $status, $e);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('trading.binance.base_url'))
            ->withHeaders(['X-MBX-APIKEY' => $this->credential->api_key])
            ->timeout(10);
    }
}
