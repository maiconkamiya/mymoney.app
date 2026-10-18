<?php

namespace App\Trading;

use App\Enums\PortfolioMode;
use App\Events\PortfolioChanged;
use App\Models\AuditLog;
use App\Models\Portfolio;
use Illuminate\Support\Facades\Cache;

/**
 * The global kill switch. While halted, no order is sent for any portfolio.
 */
class TradingHalt
{
    private const KEY = 'trading:halted';

    public function isHalted(): bool
    {
        return Cache::get(self::KEY) !== null;
    }

    public function halt(string $actor, string $reason = ''): void
    {
        Cache::forever(self::KEY, ['by' => $actor, 'reason' => $reason, 'at' => now()->toIso8601String()]);
        AuditLog::record($actor, 'trading.halted', payload: ['reason' => $reason]);
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
