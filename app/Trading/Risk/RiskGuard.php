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

    public function check(Portfolio $portfolio, OrderRequest $order): RiskDecision
    {
        $profile = $portfolio->riskProfile;
        $asset = $order->asset;

        if ($this->halt->isHalted()) {
            return RiskDecision::block('Parada geral acionada.');
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

        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->first();

        if ($order->side === OrderSide::Sell) {
            $held = $position->qty ?? 0;

            if ($held <= 0) {
                return RiskDecision::block('Venda a descoberto não é permitida.');
            }

            return $order->qty > $held
                ? new RiskDecision(true, $order->withQty($held), ['Quantidade limitada à posição.'])
                : new RiskDecision(true, $order);
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
        $limits = [
            'Limitada ao valor máximo por ordem.' => $profile->max_order_pct * $equity,
            'Limitada à exposição máxima no ativo.' => $profile->max_position_pct * $equity - ($position->qty ?? 0) * $price,
            'Limitada ao caixa disponível.' => $portfolio->cash / (1 + config('trading.costs.'.$asset->market->value.'.fee_rate')),
        ];

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
