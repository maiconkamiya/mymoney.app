<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Último preço e candle de 1 minuto de um ativo, transmitido no canal público "mercado".
 */
class CotacaoAtualizada implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * @param  array{open_at: string, open: float, high: float, low: float, close: float, volume: float}  $candle
     */
    public function __construct(
        public readonly string $symbol,
        public readonly float $price,
        public readonly array $candle,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('mercado');
    }

    public function broadcastAs(): string
    {
        return 'cotacao.atualizada';
    }
}
