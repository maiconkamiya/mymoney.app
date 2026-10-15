<?php

namespace App\MarketData;

use App\Events\CollectorStatusChanged;
use Closure;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use Throwable;

use function Ratchet\Client\connect;

/**
 * Keeps one WebSocket open to Binance and hands every frame to the handler,
 * reconnecting with a growing delay when the connection drops.
 */
class BinanceStream
{
    private int $attempt = 0;

    private bool $stopping = false;

    public function __construct(
        private readonly BinanceStreamHandler $handler,
        private readonly CollectorStatus $status,
    ) {}

    /**
     * @param  Closure(string): void  $log
     * @param  Closure(): void|null  $onConnected  Runs after every (re)connection, e.g. to backfill missed candles.
     */
    public function run(Closure $log, ?Closure $onConnected = null): void
    {
        foreach ([SIGINT, SIGTERM] as $signal) {
            Loop::addSignal($signal, function () use ($log): void {
                $this->stopping = true;
                $this->status->markDisconnected();
                $this->broadcastStatus();
                $log('Fluxo encerrado.');
                Loop::stop();
            });
        }

        $this->connect($log, $onConnected);

        Loop::run();
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function connect(Closure $log, ?Closure $onConnected): void
    {
        $url = config('market.binance.ws_url').'?streams='.implode('/', $this->handler->streams());

        $log("Conectando à Binance ({$url})...");

        connect($url)->then(
            function (WebSocket $conn) use ($log, $onConnected): void {
                $this->attempt = 0;
                $this->status->markConnected();
                $this->broadcastStatus();
                $log('Conectado.');

                if ($onConnected !== null) {
                    $this->guard($onConnected, $log);
                }

                $conn->on('message', function ($message) use ($log): void {
                    $this->guard(fn () => $this->handler->handle((string) $message), $log);
                });

                $conn->on('close', function () use ($log, $onConnected): void {
                    $this->status->markDisconnected();
                    $this->broadcastStatus();
                    $this->reconnect($log, $onConnected, 'Conexão encerrada.');
                });
            },
            fn (Throwable $e) => $this->reconnect($log, $onConnected, "Falha ao conectar: {$e->getMessage()}"),
        );
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function reconnect(Closure $log, ?Closure $onConnected, string $reason): void
    {
        if ($this->stopping) {
            return;
        }

        $this->attempt++;
        $delay = min((int) config('market.binance.reconnect_max_seconds'), 2 ** min($this->attempt, 5));

        $log("{$reason} Nova tentativa em {$delay}s.");

        Loop::addTimer($delay, fn () => $this->connect($log, $onConnected));
    }

    /**
     * One bad frame or a failed backfill must not kill the stream.
     *
     * @param  Closure(): mixed  $work
     * @param  Closure(string): void  $log
     */
    private function guard(Closure $work, Closure $log): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            $log("Erro: {$e->getMessage()}");
        }
    }

    private function broadcastStatus(): void
    {
        $this->guard(fn () => CollectorStatusChanged::dispatch(), fn (string $message) => null);
    }
}
