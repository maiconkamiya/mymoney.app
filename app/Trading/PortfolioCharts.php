<?php

namespace App\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Trading\B3\MarketPhase;

/**
 * Dados dos gráficos da tela da carteira: os ativos que ela opera, com a série
 * de candles de 1 minuto já gravada e as linhas de preço médio, stop e alvo
 * (o preço da venda registrada na corretora, ou o alvo da posição sem ela),
 * mais o teto provável da alta lido no último sinal de compra recente.
 */
class PortfolioCharts
{
    public const MAX_ASSETS = 8;

    public const CANDLES = 90;

    /** Horas em que o teto do último sinal de compra ainda aparece no gráfico. */
    public const CEILING_HOURS = 2;

    public function __construct(private readonly MarketHours $hours) {}

    /**
     * @return array{market_open: bool, phase: string, assets: list<array<string, mixed>>}
     */
    public function for(Portfolio $portfolio): array
    {
        $positions = $portfolio->openPositions()->get()->keyBy('asset_id');
        $resting = Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('side', OrderSide::Sell)
            ->where('type', OrderType::Limit)
            ->where('status', OrderStatus::Pending)
            ->whereNotNull('price')
            // The target, not the stop leg of an OCO.
            ->whereNull('stop_price')
            ->latest('id')
            ->get()
            ->unique('asset_id')
            ->mapWithKeys(fn (Order $order) => [$order->asset_id => (float) $order->price]);
        $signaled = $portfolio->signals()->latest()->limit(50)->pluck('asset_id')->unique();
        $ceilings = $portfolio->signals()
            ->where('side', OrderSide::Buy)
            ->where('created_at', '>=', now()->subHours(self::CEILING_HOURS))
            ->latest('id')
            ->get(['id', 'asset_id', 'scenario'])
            ->unique('asset_id')
            ->mapWithKeys(fn (Signal $signal) => [$signal->asset_id => $signal->scenario['chart']['extension']['ceiling'] ?? null])
            ->filter();

        $assets = Asset::query()
            ->where('market', $portfolio->market)
            ->where('active', true)
            ->where('is_benchmark', false)
            ->when($portfolio->assets()->exists(), fn ($query) => $query->whereIn('id', $portfolio->assets()->select('assets.id')))
            ->orderBy('symbol')
            ->get()
            ->sortBy(fn (Asset $asset) => match (true) {
                $positions->has($asset->id) => 0,
                $signaled->contains($asset->id) => 1,
                default => 2,
            })
            ->take(self::MAX_ASSETS)
            ->values();

        $phase = $this->hours->phase($portfolio->market);

        return [
            'market_open' => $phase === MarketPhase::Continuous,
            'phase' => $phase->value,
            'assets' => array_values($assets->map(fn (Asset $asset) => $this->asset($asset, $positions->get($asset->id), $resting->get($asset->id), $ceilings->get($asset->id)))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(Asset $asset, mixed $position, ?float $restingSell, mixed $ceiling): array
    {
        $series = Candle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->orderByDesc('open_at')
            ->limit(self::CANDLES)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Candle $c) => [
                $c->open_at->getTimestampMs(),
                (float) $c->open,
                (float) $c->high,
                (float) $c->low,
                (float) $c->close,
                (float) $c->volume,
            ])
            ->all();

        return [
            'symbol' => $asset->symbol,
            'name' => $asset->name,
            'quote_currency' => $asset->quote_currency,
            'series' => $series,
            'ceiling' => is_numeric($ceiling) ? (float) $ceiling : null,
            'position' => $position ? [
                'qty' => $position->qty,
                'avg_price' => $position->avg_price,
                'stop_price' => $position->stop_price,
                'target_price' => $restingSell ?? $position->target_price,
                'sell_registered' => $restingSell !== null,
            ] : null,
        ];
    }
}
