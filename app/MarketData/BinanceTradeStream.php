<?php

namespace App\MarketData;

use Closure;
use WebSocket\Client;
use WebSocket\Connection;
use WebSocket\Message\Text;

/**
 * WebSocket público da Binance (negócios agregados, sem chave de API).
 */
class BinanceTradeStream implements TradeStream
{
    public function __construct(private readonly string $baseUrl = 'wss://stream.binance.com:9443') {}

    public function listen(array $symbols, Closure $onTrade, Closure $onTick): void
    {
        $streams = implode('/', array_map(fn (string $s) => strtolower($s).'@aggTrade', $symbols));
        $client = new Client("{$this->baseUrl}/stream?streams={$streams}");
        $client->setTimeout(1);

        $client
            ->onText(function (Client $client, Connection $connection, Text $message) use ($onTrade): void {
                $data = json_decode($message->getContent(), true)['data'] ?? null;

                if (is_array($data) && ($data['e'] ?? null) === 'aggTrade') {
                    $onTrade((string) $data['s'], (float) $data['p'], (float) $data['q'], (int) $data['T']);
                }
            })
            ->onTick(function (Client $client) use ($onTick): void {
                if (! $onTick()) {
                    $client->stop();
                }
            })
            ->start();
    }
}
