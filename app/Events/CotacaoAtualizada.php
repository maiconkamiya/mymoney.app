<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Último preço e candle de 1 minuto de um ativo, transmitido no canal público "mercado".
 * Sai direto do coletor, sem passar pela fila: lá ele esperaria as rodadas de agentes.
 */
class CotacaoAtualizada implements ShouldBroadcastNow
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
