<?php

namespace App\Trading;

use App\Enums\PortfolioMode;
use App\Events\PortfolioChanged;
use App\Models\AuditLog;
use App\Models\Portfolio;
use Illuminate\Support\Facades\Cache;

/**
 * The global kill switch. While halted, no order is sent for any portfolio.
 * The reconciler's halt is live only: a divergence with the broker says
 * nothing about simulated portfolios, so they keep trading.
 */
class TradingHalt
{
    private const KEY = 'trading:halted';

    public function isHalted(): bool
    {
        return Cache::get(self::KEY) !== null;
    }

    /**
     * Whether the halt blocks this portfolio's orders.
     */
    public function blocks(Portfolio $portfolio): bool
    {
        return $this->isHalted() && ($portfolio->isLive() || ! $this->isLiveOnly());
    }

    /**
     * True while the only halt in place is one that spares the simulated portfolios.
     */
    public function isLiveOnly(): bool
    {
        $halt = Cache::get(self::KEY);

        return is_array($halt) && ($halt['live_only'] ?? false) === true;
    }

    /**
     * @param  bool  $liveOnly  blocks only the live portfolios; never narrows a halt for every portfolio already in place
     */
    public function halt(string $actor, string $reason = '', bool $liveOnly = false): void
    {
        $liveOnly = $liveOnly && (! $this->isHalted() || $this->isLiveOnly());

        Cache::forever(self::KEY, ['by' => $actor, 'reason' => $reason, 'at' => now()->toIso8601String(), 'live_only' => $liveOnly]);
        AuditLog::record($actor, 'trading.halted', payload: ['reason' => $reason, 'live_only' => $liveOnly]);
        $this->notifyLivePortfolios();
    }

    public function resume(string $actor): void
    {
        Cache::forget(self::KEY);
        AuditLog::record($actor, 'trading.resumed');
        $this->notifyLivePortfolios();
    }

    /**
     * A parada fica só no cache: as carteiras reais abertas na tela recarregam o aviso.
     */
    private function notifyLivePortfolios(): void
    {
        Portfolio::query()->where('mode', PortfolioMode::Live)->pluck('id')
            ->each(fn (int $id) => PortfolioChangeBroadcaster::notify($id, [PortfolioChanged::SCOPE_BALANCE, PortfolioChanged::SCOPE_MICRO]));
    }
}
