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
            throw new LiveTradingException('Sem conexão com a Binance: '.$e->getMessage(), 0, $e, uncertain: true);
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

        $binanceCode = is_numeric($e->response->json('code')) ? (int) $e->response->json('code') : null;
        $known = $binanceCode !== null ? $this->knownError($binanceCode, $message) : null;

        $text = match (true) {
            $known !== null => $known,
            $status === 451 => "A Binance bloqueou este servidor por região (451) em {$base}. Use um servidor em região liberada ou ajuste BINANCE_API_URL.",
            $status === 401 || $status === 403 => 'A Binance recusou a chave (verifique permissões e a restrição de IP)'.($message !== '' ? ": {$message}" : '.'),
            $status === 418 || $status === 429 => 'A Binance limitou as chamadas deste servidor. Tente de novo em alguns minutos.',
            default => "Erro da Binance ({$status})".($message !== '' ? ": {$message}" : '.'),
        };

        Log::warning('binance.request_failed', ['status' => $status, 'base_url' => $base, 'code' => $e->response->json('code')]);

        return new LiveTradingException($text, $status, $e, $binanceCode, uncertain: $status >= 500);
    }

    /**
     * Binance's order errors that a person can act on, in Portuguese.
     */
    private function knownError(int $code, string $message): ?string
    {
        return match (true) {
            $code === -2010 && stripos($message, 'insufficient balance') !== false => 'Saldo insuficiente na Binance para esta ordem.',
            $code === -2010 && stripos($message, 'duplicate') !== false => 'A Binance já recebeu esta ordem (identificador duplicado).',
            $code === -2010 => "A Binance recusou a ordem: {$message}",
            $code === -2013 => 'A ordem não existe na Binance.',
            $code === -2011 => 'A Binance não encontrou a ordem para cancelar (talvez já executada ou cancelada).',
            $code === -1013 || $code === -1111 => 'A ordem ficou fora dos limites da Binance para este par (quantidade mínima, passo ou valor mínimo).',
            $code === -1021 => 'O relógio do servidor está fora de sincronia com a Binance (timestamp fora da janela).',
            $code === -1121 => 'Par inválido na Binance.',
            $code === -2014 || $code === -2015 => 'A Binance recusou a chave (chave inválida, sem permissão de negociação ou IP não liberado).',
            $code === -1003 => 'A Binance limitou as chamadas deste servidor. Tente de novo em alguns minutos.',
            default => null,
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('trading.binance.base_url'))
            ->withHeaders(['X-MBX-APIKEY' => $this->credential->api_key])
            ->timeout(10);
    }
}
