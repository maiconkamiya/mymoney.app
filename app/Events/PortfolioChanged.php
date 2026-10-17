<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Algo mudou na carteira (saldo, posições, ordens, sinais ou ciclos de micro
 * operações). Vai só para o canal privado do dono; a tela recarrega apenas os
 * blocos citados em `scopes`, sem polling.
 */
class PortfolioChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const SCOPE_BALANCE = 'balance';

    public const SCOPE_POSITIONS = 'positions';

    public const SCOPE_ORDERS = 'orders';

    public const SCOPE_SIGNALS = 'signals';

    public const SCOPE_MICRO = 'micro';

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly int $portfolioId,
        public readonly array $scopes,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('portfolios.'.$this->portfolioId)];
    }

    public function broadcastAs(): string
    {
        return 'portfolio.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'portfolio_id' => $this->portfolioId,
            'scopes' => $this->scopes,
            'at' => now()->toIso8601String(),
        ];
    }
}
