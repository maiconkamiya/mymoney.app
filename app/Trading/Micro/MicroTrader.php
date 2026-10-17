<?php

namespace App\Trading\Micro;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\MarketData\BinanceSymbols;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Data\OrderRequest;
use App\Trading\Live\LiveLimits;
use App\Trading\OrderExecutor;
use App\Trading\Risk\RiskGuard;
use App\Trading\TradingHalt;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the "Micro operações" mode of one portfolio, once a minute: closes the
 * books of finished cycles, forces out the ones held too long, and opens new
 * ones on the most volatile pairs whose chart allows an entry.
 *
 * Exits (target and stop) are the ones every position already has: the
 * regular exits job sells them. Every order goes through the executor, so the
 * risk guard, the live caps and "Parar tudo" apply as for any other order.
 */
class MicroTrader
{
    public const ACTOR = 'micro';

    public function __construct(
        private readonly MicroRanker $ranker,
        private readonly MicroCosts $costs,
        private readonly OrderExecutor $executor,
        private readonly LiveLimits $live,
        private readonly TradingHalt $halt,
        private readonly RiskGuard $risk,
        private readonly BinanceSymbols $symbols,
    ) {}

    /**
     * @return int number of cycles opened
     */
    public function run(Portfolio $portfolio): int
    {
        if (! $portfolio->micro_enabled) {
            return 0;
        }

        Cache::put($this->runKey($portfolio), now()->toIso8601String(), now()->addDay());

        $this->settle($portfolio);
        $this->expire($portfolio);

        if ($this->state($portfolio)['code'] !== 'active') {
            return 0;
        }

        $gross = $this->costs->grossMarginPct($portfolio->micro_net_margin_pct);
        $open = $portfolio->microCycles()->where('status', 'open')->count();
        $opened = 0;

        $blocks = [];

        foreach ($this->ranker->rank($portfolio, $gross) as $row) {
            if ($portfolio->micro_daily_operations !== null && $this->openedToday($portfolio) >= $portfolio->micro_daily_operations) {
                break;
            }

            if (! $row['setup']->entry) {
                continue;
            }

            $reason = $this->gate($portfolio, $row['asset'], $row['setup'], $open);

            if ($reason !== null) {
                $blocks[] = $row['asset']->symbol.': '.$reason;

                continue;
            }

            Cache::forget($this->blockKey($portfolio));

            if ($this->open($portfolio, $row['asset'], $row['setup'], $gross)) {
                $open++;
                $opened++;
            } elseif (($failure = Cache::get($this->blockKey($portfolio))) !== null) {
                $blocks[] = $failure;
            }
        }

        // Só o motivo da última rodada: ciclo aberto ou nenhum par liberado limpam o aviso velho.
        if ($blocks === []) {
            Cache::forget($this->blockKey($portfolio));
        } else {
            Cache::put($this->blockKey($portfolio), implode(' · ', array_unique($blocks)), now()->addHour());
        }

        return $opened;
    }

    /**
     * Why the mode is trading, or why it is not.
     *
     * @return array{code: string, label: string}
     */
    public function state(Portfolio $portfolio): array
    {
        if (! $portfolio->micro_enabled) {
            return ['code' => 'off', 'label' => 'Desligado'];
        }

        if (! $portfolio->isRunning()) {
            return ['code' => 'portfolio_stopped', 'label' => 'Carteira fora do período de execução'];
        }

        if ($this->halt->isHalted() || $portfolio->isOrdersBlocked()) {
            return ['code' => 'blocked', 'label' => 'Ordens bloqueadas ("Parar tudo")'];
        }

        if ($portfolio->isLive()) {
            if (! $this->live->enabled()) {
                return ['code' => 'live_off', 'label' => 'Execução real desligada no servidor'];
            }

            if (! $portfolio->autoExecutesLive()) {
                return ['code' => 'live_not_confirmed', 'label' => 'Carteira real sem a execução automática confirmada (CONFIRMO)'];
            }

            if ($this->live->remainingToday($portfolio) < 2 * $portfolio->micro_order_value) {
                return ['code' => 'live_cap', 'label' => 'Teto diário de ordens reais esgotado'];
            }
        }

        if ($portfolio->micro_daily_operations !== null && $this->openedToday($portfolio) >= $portfolio->micro_daily_operations) {
            return ['code' => 'operations_done', 'label' => 'Quantidade de operações do dia atingida'];
        }

        $pnl = $this->pnlToday($portfolio);

        if ($portfolio->micro_daily_target !== null && $pnl >= $portfolio->micro_daily_target) {
            return ['code' => 'target_hit', 'label' => 'Meta do dia batida'];
        }

        if ($portfolio->micro_daily_loss_limit !== null && $pnl <= -$portfolio->micro_daily_loss_limit) {
            return ['code' => 'loss_limit', 'label' => 'Perda máxima do dia atingida'];
        }

        return ['code' => 'active', 'label' => 'Operando'];
    }

    /**
     * Cycles opened today, still open or already closed.
     */
    public function openedToday(Portfolio $portfolio): int
    {
        return $portfolio->microCycles()->where('opened_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Smallest order value the owner may set: Binance's 5 USDT spot minimum on a
     * real portfolio, a lower floor on a simulated one.
     */
    public static function minOrderValue(Portfolio $portfolio): float
    {
        return (float) config($portfolio->isLive() ? 'trading.micro.min_order_value' : 'trading.micro.min_order_value_paper');
    }

    /**
     * Net profit of the cycles closed today.
     */
    public function pnlToday(Portfolio $portfolio): float
    {
        return (float) $portfolio->microCycles()
            ->where('status', 'closed')
            ->where('closed_at', '>=', now()->startOfDay())
            ->sum('net_pnl');
    }

    /**
     * Book the cycles whose position was sold (target, stop, forced exit or "Parar tudo").
     */
    public function settle(Portfolio $portfolio): void
    {
        $cycles = $portfolio->microCycles()->where('status', 'open')->with('position')->get();

        foreach ($cycles as $cycle) {
            $position = $cycle->position;

            if ($position === null || $position->closed_at === null) {
                continue;
            }

            $sell = Order::query()
                ->where('portfolio_id', $portfolio->id)
                ->where('asset_id', $cycle->asset_id)
                ->where('side', OrderSide::Sell)
                ->where('status', OrderStatus::Filled)
                ->where('filled_at', '>=', $cycle->opened_at)
                ->latest('filled_at')
                ->first();

            $exitPrice = $sell->price ?? $position->avg_price;
            $fees = $cycle->fees + ($sell->fees ?? 0);
            $net = $cycle->qty * ($exitPrice - $cycle->entry_price) - $fees;

            $cycle->update([
                'status' => 'closed',
                'exit_price' => $exitPrice,
                'fees' => $fees,
                'net_pnl' => $net,
                'exit_reason' => $position->exit_reason ?? 'sold',
                'closed_at' => $position->closed_at,
            ]);

            AuditLog::record(self::ACTOR, 'micro.closed', $cycle, [
                'portfolio_id' => $portfolio->id,
                'exit_reason' => $cycle->exit_reason,
                'net_pnl' => round($net, 8),
            ]);
        }
    }

    /**
     * Sell what stayed open longer than the limit: a cycle is meant to be quick.
     */
    private function expire(Portfolio $portfolio): void
    {
        $cycles = $portfolio->microCycles()
            ->where('status', 'open')
            ->where('opened_at', '<=', now()->subMinutes((int) config('trading.micro.max_hold_minutes')))
            ->with('position.asset')
            ->get();

        foreach ($cycles as $cycle) {
            if ($cycle->position !== null && $cycle->position->closed_at === null) {
                $this->executor->closePosition($cycle->position, 'micro_time', self::ACTOR);
            }
        }

        $this->settle($portfolio);
    }

    /**
     * Why the pair cannot get a new cycle right now, or null when it can.
     * The same checks the real run applies after the chart says "enter", so
     * `micro:run --explain` and the card can show them.
     */
    public function gate(Portfolio $portfolio, Asset $asset, MicroSetup $setup, int $openCycles): ?string
    {
        if ($openCycles >= (int) config('trading.micro.max_open_cycles')) {
            return 'já há '.$openCycles.' ciclos abertos (máximo '.(int) config('trading.micro.max_open_cycles').').';
        }

        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->first();

        if ($position !== null) {
            $isCycle = $portfolio->microCycles()->where('position_id', $position->id)->where('status', 'open')->exists();

            return $isCycle
                ? 'já tem um ciclo em andamento neste par.'
                : 'a carteira já tem posição aberta neste par (ex.: saldo da conta Binance); o modo não compra por cima.';
        }

        $cooldown = (int) config('trading.micro.cooldown_minutes');

        if ($portfolio->microCycles()->where('asset_id', $asset->id)->where('status', 'closed')->where('exit_reason', 'stop')->where('closed_at', '>=', now()->subMinutes($cooldown))->exists()) {
            return 'pausa de '.$cooldown.' minutos depois de um stop neste par.';
        }

        if ($portfolio->isLive()) {
            $this->refreshFilters($asset);
        }

        // On a real portfolio the pair's own minimum order may be above the 5 USDT floor.
        if ($portfolio->isLive() && $portfolio->micro_order_value < (float) $asset->min_notional) {
            return 'o mínimo do par é '.(float) $asset->min_notional.' USDT e o aporte é '.$portfolio->micro_order_value.'.';
        }

        $decision = $this->risk->check($portfolio, $this->buyRequest($portfolio, $asset, $setup, $this->costs->grossMarginPct($portfolio->micro_net_margin_pct)));

        if (! $decision->allowed) {
            return 'guarda de risco: '.$decision->summary();
        }

        $wanted = $this->entryQty($portfolio, $asset, $setup->price) * $setup->price;
        $minimum = max(self::minOrderValue($portfolio), $portfolio->isLive() ? (float) $asset->min_notional : 0.0);

        if ($wanted > $portfolio->micro_order_value * 1.1) {
            return sprintf('o passo de quantidade do par (%s) obriga uma ordem de %.2f USDT, acima do aporte de %.2f.', (float) $asset->lot_size, $wanted, $portfolio->micro_order_value);
        }

        $value = ($decision->order->qty ?? 0) * $setup->price;

        if ($value < $minimum * 0.99) {
            return sprintf('o aporte de %.2f USDT seria reduzido pela guarda de risco para %.2f USDT, abaixo do mínimo de %.2f USDT, e a ordem não é enviada. %s Aumente o saldo ou o limite por ordem do perfil de risco da carteira.', $portfolio->micro_order_value, $value, $minimum, $decision->summary());
        }

        return null;
    }

    /**
     * The quantity that fits the pair's filters: rounded UP to the step so the
     * value never falls under the minimum order (a floor to the step turned
     * 5 USDT into 4.9 and the exchange refused it), and at least the pair's
     * minimum quantity and, on a real portfolio, its minimum order value plus
     * a 1% margin for the price moving before the fill.
     */
    private function entryQty(Portfolio $portfolio, Asset $asset, float $price): float
    {
        $value = $portfolio->micro_order_value;

        if ($portfolio->isLive()) {
            $value = max($value, (float) $asset->min_notional * 1.01);
        }

        $qty = max($value / $price, (float) $asset->min_qty);
        $step = (float) $asset->lot_size;

        return $step > 0 ? ceil(round($qty / $step, 8)) * $step : $qty;
    }

    /**
     * Assets created by the account sync carry placeholder filters; read the
     * pair's real ones from the exchange once an hour before ordering.
     */
    private function refreshFilters(Asset $asset): void
    {
        if ($asset->min_notional !== null) {
            return;
        }

        Cache::remember('micro:filters:'.$asset->id, now()->addHour(), function () use ($asset): bool {
            try {
                $pair = $this->symbols->find($asset->symbol);
            } catch (\Throwable) {
                return false;
            }

            if ($pair === null) {
                return false;
            }

            $asset->update([
                'lot_size' => $pair['lot_size'],
                'tick_size' => $pair['tick_size'],
                'min_qty' => $pair['min_qty'],
                'min_notional' => $pair['min_notional'],
            ]);

            return true;
        });
    }

    private function buyRequest(Portfolio $portfolio, Asset $asset, MicroSetup $setup, float $grossPct): OrderRequest
    {
        return new OrderRequest(
            asset: $asset,
            side: OrderSide::Buy,
            qty: $this->entryQty($portfolio, $asset, $setup->price),
            stopPrice: $setup->price * (1 - $grossPct / 100 * (float) config('trading.micro.stop_ratio')),
            clientId: 'micro-'.$portfolio->id.'-'.$asset->id.'-'.now()->format('YmdHi'),
        );
    }

    private function open(Portfolio $portfolio, Asset $asset, MicroSetup $setup, float $grossPct): bool
    {
        $outcome = $this->executor->placeDirect($portfolio, $this->buyRequest($portfolio, $asset, $setup, $grossPct), self::ACTOR, 'micro');

        if (is_string($outcome) || $outcome->status !== OrderStatus::Filled) {
            Cache::put($this->blockKey($portfolio), $asset->symbol.': '.(is_string($outcome) ? $outcome : 'corretora recusou: '.$outcome->reject_reason), now()->addHour());

            return false;
        }

        /** @var Position|null $position */
        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->latest('id')->first();

        if ($position === null) {
            return false;
        }

        $entry = $outcome->price ?? $setup->price;
        $target = $entry * (1 + $grossPct / 100);
        $stop = $entry * (1 - $grossPct / 100 * (float) config('trading.micro.stop_ratio'));

        $position->update(['target_price' => $target, 'stop_price' => $stop]);

        $cycle = $portfolio->microCycles()->create([
            'asset_id' => $asset->id,
            'position_id' => $position->id,
            'status' => 'open',
            'qty' => $position->qty,
            'entry_price' => $entry,
            'target_price' => $target,
            'stop_price' => $stop,
            'fees' => $outcome->fees,
            'gross_margin_pct' => $grossPct,
            'analysis' => $setup->toArray(),
            'opened_at' => now(),
        ]);

        AuditLog::record(self::ACTOR, 'micro.opened', $cycle, ['portfolio_id' => $portfolio->id, 'symbol' => $asset->symbol, 'order_id' => $outcome->id]);

        return true;
    }

    /**
     * When the engine last ran for this portfolio, so a stopped scheduler shows.
     */
    public function lastRun(Portfolio $portfolio): ?string
    {
        return Cache::get($this->runKey($portfolio));
    }

    private function runKey(Portfolio $portfolio): string
    {
        return 'micro:last-run:'.$portfolio->id;
    }

    public function lastBlock(Portfolio $portfolio): ?string
    {
        return Cache::get($this->blockKey($portfolio));
    }

    private function blockKey(Portfolio $portfolio): string
    {
        return 'micro:last-block:'.$portfolio->id;
    }
}
