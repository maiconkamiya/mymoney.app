<?php

namespace App\Trading\Risk;

use App\Enums\OrderSide;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\OrderRequest;
use App\Trading\Live\LiveLimits;
use App\Trading\PortfolioValuation;
use App\Trading\TradingHalt;

/**
 * Deterministic limits applied to every order, simulated or live. No model is
 * consulted here: these rules hold even if every agent is wrong.
 *
 * Orders that only reduce risk (sells of an existing position) pass the loss
 * limits; orders that add risk are trimmed or blocked.
 */
class RiskGuard
{
    public function __construct(
        private readonly TradingHalt $halt,
        private readonly PriceFeed $prices,
        private readonly PortfolioValuation $valuation,
        private readonly LiveLimits $live,
    ) {}

    /**
     * @param  bool  $ownerSized  the owner chose this order's value himself (micro operations): the profile's
     *                            percentage of equity per order and per asset does not shrink it. Cash, the live
     *                            caps, daily loss, drawdown, open positions, halts and every other rule still apply.
     */
    public function check(Portfolio $portfolio, OrderRequest $order, bool $ownerSized = false): RiskDecision
    {
        $profile = $portfolio->riskProfile;
        $asset = $order->asset;

        if ($this->halt->blocks($portfolio)) {
            return RiskDecision::block('Parada geral acionada.');
        }

        if ($portfolio->isOrdersBlocked()) {
            return RiskDecision::block('Ordens bloqueadas nesta carteira ("Parar tudo").');
        }

        if ($asset->market !== $portfolio->market) {
            return RiskDecision::block('Ativo fora do mercado da carteira.');
        }

        if ($portfolio->isLive() && ! $this->live->enabled()) {
            return RiskDecision::block('Execução real desligada.');
        }

        if (! $portfolio->isRunning()) {
            return RiskDecision::block('Carteira não está em execução.');
        }

        $quote = $this->prices->quote($asset);
        $maxAge = config('trading.max_price_age_seconds.'.$asset->market->value);

        if ($quote === null || $quote->ageInSeconds() > $maxAge) {
            return RiskDecision::block('Cotação ausente ou atrasada para '.$asset->symbol.'.');
        }

        $position = $portfolio->openPositions()->where('asset_id', $asset->id)
            ->when($order->side === OrderSide::Sell && $order->positionId !== null, fn ($query) => $query->whereKey($order->positionId))
            ->first();

        if ($order->side === OrderSide::Sell) {
            $held = $position->qty ?? 0;

            if ($held <= 0) {
                return RiskDecision::block('Venda a descoberto não é permitida.');
            }

            // Every sale stays on the pair's step: a quantity off the step is refused by the exchange (LOT_SIZE).
            $lot = (float) $asset->lot_size;
            $qty = min($order->qty, $held);
            $qty = $lot > 0 ? floor(round($qty / $lot, 8)) * $lot : $qty;

            if ($qty <= 0) {
                return RiskDecision::block('Quantidade da posição abaixo do passo do par.');
            }

            return $order->qty > $held
                ? new RiskDecision(true, $order->withQty($qty), ['Quantidade limitada à posição.'])
                : new RiskDecision(true, abs($qty - $order->qty) > 1e-12 ? $order->withQty($qty) : $order);
        }

        // The exchange stops trading the pair on its end date: nothing more is bought, only sold.
        if ($asset->tradingEnded()) {
            return RiskDecision::block('Negociação de '.$asset->symbol.' encerrada em '.$asset->trading_ends_on?->format('d/m/Y').': o app só vende.');
        }

        if ($profile->require_stop && $order->stopPrice === null) {
            return RiskDecision::block('Toda compra precisa de stop.');
        }

        if ($position === null && $portfolio->openPositions()->count() >= $profile->max_open_positions) {
            return RiskDecision::block('Limite de posições abertas atingido.');
        }

        $equity = $this->valuation->equity($portfolio);
        $dailyLoss = 1 - $equity / max($this->valuation->startOfDayEquity($portfolio), 0.00000001);
        $drawdown = 1 - $equity / max($this->valuation->peakEquity($portfolio), 0.00000001);

        if ($dailyLoss >= $profile->max_daily_loss_pct) {
            return RiskDecision::block('Perda máxima do dia atingida.');
        }

        if ($drawdown >= $profile->max_drawdown_pct) {
            return RiskDecision::block('Drawdown máximo atingido.');
        }

        $price = $quote->price;
        $reasons = [];
        $limits = $ownerSized ? [] : [
            'Limitada ao valor máximo por ordem.' => $profile->max_order_pct * $equity,
            'Limitada à exposição máxima no ativo.' => $profile->max_position_pct * $equity - ($position->qty ?? 0) * $price,
        ];
        $limits['Limitada ao caixa disponível.'] = $portfolio->cash / (1 + config('trading.costs.'.$asset->market->value.'.fee_rate'));

        if ($portfolio->isLive()) {
            $limits['Limitada ao valor máximo por ordem real.'] = $this->live->maxOrderValue();
            $limits['Limitada ao limite diário real.'] = $this->live->remainingToday($portfolio);
        }

        $qty = $order->qty;

        foreach ($limits as $reason => $maxValue) {
            if ($qty * $price > $maxValue) {
                $qty = max($maxValue, 0) / $price;
                $reasons[] = $reason;
            }
        }

        $lot = (float) $asset->lot_size;
        $qty = $lot > 0 ? floor(round($qty / $lot, 8)) * $lot : $qty;

        if ($qty <= 0) {
            return RiskDecision::block(trim('Quantidade zerada pelos limites. '.implode(' ', $reasons)));
        }

        return new RiskDecision(true, $order->withQty($qty), $reasons);
    }
}
