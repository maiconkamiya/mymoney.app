<?php

namespace App\Trading\Live;

use App\Models\BrokerCredential;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal signed REST client for the Binance spot API.
 */
class BinanceClient
{
    private const OFFSET_TTL_MINUTES = 5;

    // A Binance só recusa timestamp mais de 1000 ms à frente da hora dela; atrasado vale até recvWindow.
    // Por isso o carimbo anda um pouco para trás, absorvendo o erro de medir o offset pela rede.
    private const SAFETY_MARGIN_MS = 500;

    private static ?CurlHandler $handler = null;

    public function __construct(private readonly BrokerCredential $credential) {}

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     *
     * @throws LiveTradingException on geoblocking (451), connection failures and rejected keys
     */
    public function signed(string $method, string $path, array $params = []): array
    {
        try {
            return $this->send($method, $path, $params);
        } catch (RequestException $e) {
            // -1021: o relógio local saiu da janela. Recalcula o desvio e tenta uma vez só.
            if ($this->isTimestampError($e)) {
                $this->syncClock();

                try {
                    return $this->send($method, $path, $params);
                } catch (RequestException $retry) {
                    throw $this->translate($retry);
                }
            }

            throw $this->translate($e);
        } catch (ConnectionException $e) {
            throw new LiveTradingException('Sem conexão com a Binance: '.$e->getMessage(), 0, $e, uncertain: true);
        }
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $params): array
    {
        $params['timestamp'] = $this->now() + $this->clockOffset() - self::SAFETY_MARGIN_MS;
        $params['recvWindow'] = config('trading.binance.recv_window');

        $query = http_build_query($params);
        $query .= '&signature='.hash_hmac('sha256', $query, $this->credential->api_secret);

        /** @var array<string, mixed> $json */
        $json = $this->http()->send($method, $path.'?'.$query)->throw()->json();

        return $json;
    }

    /**
     * Milliseconds to add to the local clock to match Binance's. Cached for a
     * few minutes; when /api/v3/time is unreachable the local clock is used.
     */
    private function clockOffset(): int
    {
        $offset = Cache::get($this->offsetKey());

        return is_int($offset) ? $offset : $this->syncClock();
    }

    private function syncClock(): int
    {
        try {
            $before = $this->now();
            $serverTime = $this->http()->get('/api/v3/time')->throw()->json('serverTime');
            $after = $this->now();
        } catch (ConnectionException|RequestException) {
            return 0;
        }

        if (! is_numeric($serverTime)) {
            return 0;
        }

        // Compara com o meio da viagem para não contar a latência como desvio.
        $offset = (int) $serverTime - intdiv($before + $after, 2);
        Cache::put($this->offsetKey(), $offset, now()->addMinutes(self::OFFSET_TTL_MINUTES));

        return $offset;
    }

    private function isTimestampError(RequestException $e): bool
    {
        return (int) $e->response->json('code') === -1021;
    }

    private function offsetKey(): string
    {
        return 'binance.clock_offset.'.md5((string) config('trading.binance.base_url'));
    }

    private function now(): int
    {
        return (int) (microtime(true) * 1000);
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

        Log::warning('binance.request_failed', ['status' => $status, 'base_url' => $base, 'code' => $binanceCode]);

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
            $code === -1021 => 'O relógio desta máquina está fora de sincronia com o da Binance, mesmo depois do ajuste automático. Acerte o relógio do sistema (no WSL: sudo hwclock -s) e tente de novo.',
            $code === -1121 => 'Par inválido na Binance.',
            $code === -2014 || $code === -2015 => 'A Binance recusou a chave (chave inválida, sem permissão de negociação ou IP não liberado).',
            $code === -1003 => 'A Binance limitou as chamadas deste servidor. Tente de novo em alguns minutos.',
            default => null,
        };
    }

    private function http(): PendingRequest
    {
        // One cURL handler per process keeps the HTTPS connection to Binance open between calls:
        // a new one per order paid DNS, TCP and TLS again, several round trips before the order left (#196).
        self::$handler ??= new CurlHandler;

        return Http::baseUrl(config('trading.binance.base_url'))
            ->setHandler(self::$handler)
            ->withHeaders(['X-MBX-APIKEY' => $this->credential->api_key])
            ->timeout(10);
    }
}
