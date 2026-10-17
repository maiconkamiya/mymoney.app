<?php

namespace App\Trading\Live;

use App\Enums\OrderStatus;
use App\Models\BrokerCredential;
use App\Models\Order;
use App\Models\Portfolio;
use App\Trading\TradingHalt;

/**
 * Absolute guard rails for real money: the master switch, the kill switch,
 * the per-portfolio stop ("Parar tudo"), credential hygiene and the per-order
 * and per-day value caps.
 */
class LiveLimits
{
    public function __construct(private readonly TradingHalt $halt) {}

    public function enabled(): bool
    {
        return (bool) config('trading.live.enabled');
    }

    public function maxOrderValue(): float
    {
        return (float) config('trading.live.max_order_value');
    }

    public function maxDailyValue(): float
    {
        return (float) config('trading.live.max_daily_value');
    }

    /**
     * Value of real orders sent today that still count against the daily cap.
     */
    public function spentToday(Portfolio $portfolio): float
    {
        return (float) Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Filled, OrderStatus::PartiallyFilled])
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('COALESCE(SUM(qty * COALESCE(price, 0)), 0) as spent')
            ->value('spent');
    }

    public function remainingToday(Portfolio $portfolio): float
    {
        return max($this->maxDailyValue() - $this->spentToday($portfolio), 0);
    }

    /**
     * Last line of defence, called by the real brokers right before sending.
     *
     * @throws LiveTradingException
     */
    public function assertCanSend(Portfolio $portfolio, ?BrokerCredential $credential): BrokerCredential
    {
        if (! $this->enabled()) {
            throw new LiveTradingException('Execução real desligada (TRADING_LIVE_ENABLED).');
        }

        if ($this->halt->isHalted()) {
            throw new LiveTradingException('Parada geral acionada.');
        }

        if (! $portfolio->isLive()) {
            throw new LiveTradingException('Só carteiras reais podem enviar ordens reais.');
        }

        if ($portfolio->isOrdersBlocked()) {
            throw new LiveTradingException('Ordens bloqueadas nesta carteira ("Parar tudo"). Libere na tela da carteira.');
        }

        if ($portfolio->auto_execute && $portfolio->live_auto_confirmed_at === null) {
            throw new LiveTradingException('Execução automática real sem a confirmação do dono (CONFIRMO).');
        }

        if ($credential === null) {
            throw new LiveTradingException('Carteira sem credencial da corretora.');
        }

        if ($credential->can_withdraw || $credential->verified_at === null) {
            throw new LiveTradingException('Credencial não verificada ou com permissão de saque.');
        }

        return $credential;
    }
}
