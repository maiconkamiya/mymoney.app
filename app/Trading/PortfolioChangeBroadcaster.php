<?php

namespace App\Trading;

use App\Events\PortfolioChanged;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Signal;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Liga os modelos da carteira ao evento `portfolio.changed`, para a tela do dono
 * acompanhar o que o scheduler, a fila e o coletor fazem sem F5. Falha do
 * websocket nunca derruba a gravação.
 */
class PortfolioChangeBroadcaster
{
    public static function register(): void
    {
        Portfolio::saved(function (Portfolio $portfolio) {
            if ($portfolio->wasChanged() || $portfolio->wasRecentlyCreated) {
                self::send($portfolio->id, [PortfolioChanged::SCOPE_BALANCE, PortfolioChanged::SCOPE_POSITIONS, PortfolioChanged::SCOPE_MICRO]);
            }
        });
        Position::saved(fn (Position $model) => self::send($model->portfolio_id, [PortfolioChanged::SCOPE_POSITIONS, PortfolioChanged::SCOPE_BALANCE]));
        Order::saved(fn (Order $model) => self::send($model->portfolio_id, [PortfolioChanged::SCOPE_ORDERS, PortfolioChanged::SCOPE_BALANCE]));
        Signal::saved(fn (Signal $model) => self::send($model->portfolio_id, [PortfolioChanged::SCOPE_SIGNALS]));
        MicroCycle::saved(fn (MicroCycle $model) => self::send($model->portfolio_id, [PortfolioChanged::SCOPE_MICRO, PortfolioChanged::SCOPE_POSITIONS]));
    }

    /**
     * @param  list<string>  $scopes
     */
    private static function send(int $portfolioId, array $scopes): void
    {
        try {
            PortfolioChanged::dispatch($portfolioId, $scopes);
        } catch (Throwable $e) {
            Log::warning('Portfolio change not broadcast', ['portfolio' => $portfolioId, 'error' => $e->getMessage()]);
        }
    }
}
