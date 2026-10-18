<?php

namespace App\Trading;

use App\Enums\Market;
use App\Events\PortfolioChanged;
use App\Models\AgentVote;
use App\Models\Asset;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
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
        // `updated` (e não `saved`) porque o increment/decrement do caixa só dispara esse.
        $portfolio = fn (Portfolio $model) => self::notify($model->id, [PortfolioChanged::SCOPE_BALANCE, PortfolioChanged::SCOPE_POSITIONS, PortfolioChanged::SCOPE_MICRO]);
        Portfolio::created($portfolio);
        Portfolio::updated($portfolio);

        $models = [
            Position::class => [PortfolioChanged::SCOPE_POSITIONS, PortfolioChanged::SCOPE_BALANCE, PortfolioChanged::SCOPE_MICRO],
            Order::class => [PortfolioChanged::SCOPE_ORDERS, PortfolioChanged::SCOPE_BALANCE, PortfolioChanged::SCOPE_MICRO],
            Signal::class => [PortfolioChanged::SCOPE_SIGNALS],
            MicroCycle::class => [PortfolioChanged::SCOPE_MICRO, PortfolioChanged::SCOPE_POSITIONS],
        ];

        foreach ($models as $class => $scopes) {
            $class::saved(fn (Position|Order|Signal|MicroCycle $model) => self::notify($model->portfolio_id, $scopes));
            $class::deleted(fn (Position|Order|Signal|MicroCycle $model) => self::notify($model->portfolio_id, $scopes));
        }

        // Cada voto grava os tokens da chamada; a carteira vem do sinal.
        AgentVote::created(function (AgentVote $vote): void {
            $portfolioId = Signal::query()->whereKey($vote->signal_id)->value('portfolio_id');

            if ($portfolioId !== null) {
                self::notify((int) $portfolioId, [PortfolioChanged::SCOPE_TOKENS]);
            }
        });

        PortfolioSnapshot::created(fn (PortfolioSnapshot $model) => self::notify($model->portfolio_id, [PortfolioChanged::SCOPE_BALANCE]));

        // Ativo cadastrado, reativado, removido ou adotado pelo dono muda a lista para marcar
        // e os gráficos das carteiras que acompanham todos. Taxas de lote e passo não contam.
        Asset::created(fn (Asset $asset) => self::notifyMarket($asset->market, [PortfolioChanged::SCOPE_ASSETS]));
        Asset::updated(function (Asset $asset): void {
            if ($asset->wasChanged(['active', 'discovered', 'is_benchmark', 'symbol', 'name'])) {
                self::notifyMarket($asset->market, [PortfolioChanged::SCOPE_ASSETS]);
            }
        });
        Asset::deleted(fn (Asset $asset) => self::notifyMarket($asset->market, [PortfolioChanged::SCOPE_ASSETS]));
    }

    /**
     * Todas as carteiras de um mercado (o que é comum a elas, como a lista de ativos e o placar dos agentes).
     *
     * @param  list<string>  $scopes
     */
    public static function notifyMarket(Market $market, array $scopes): void
    {
        Portfolio::query()->where('market', $market)->pluck('id')
            ->each(fn (int $id) => self::notify($id, $scopes));
    }

    /**
     * As carteiras que mostram o ativo nos gráficos, quando chegam velas dele fora do coletor.
     */
    public static function notifyCandles(Asset $asset): void
    {
        Portfolio::query()->tracking($asset)->pluck('id')
            ->each(fn (int $id) => self::notify($id, [PortfolioChanged::SCOPE_CHARTS]));
    }

    /**
     * Para o que muda fora dos modelos (cache do motor, update em massa, pivô).
     *
     * @param  list<string>  $scopes
     */
    public static function notify(int $portfolioId, array $scopes): void
    {
        try {
            PortfolioChanged::dispatch($portfolioId, $scopes);
        } catch (Throwable $e) {
            Log::warning('Portfolio change not broadcast', ['portfolio' => $portfolioId, 'error' => $e->getMessage()]);
        }
    }
}
