<?php

namespace App\Trading\Micro;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\MarketData\BinanceSymbols;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Contracts\PriceFeed;
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
 * Right after the buy a limit sell rests at the target on the exchange; the
 * regular exits job books its fill, and the stop or the time limit cancel it
 * before selling at market. Every order goes through the executor, so the
 * risk guard, the live caps and "Parar tudo" apply as for any other order.
 */
class MicroTrader
{
    public const ACTOR = 'micro';

    public function __construct(
        private readonly MicroRanker $ranker,
        private readonly MicroCosts $costs,
        private readonly MicroRules $rules,
        private readonly OrderExecutor $executor,
        private readonly LiveLimits $live,
        private readonly TradingHalt $halt,
        private readonly RiskGuard $risk,
        private readonly BinanceSymbols $symbols,
        private readonly PriceFeed $prices,
    ) {}

    /**
     * @return int number of cycles opened
     */
    public function run(Portfolio $portfolio): int
    {
        // The owner's "liquidar com lucro" works even with the mode off: it only sells.
        $this->liquidate($portfolio);

        if (! $portfolio->micro_enabled) {
            return 0;
        }

        Cache::put($this->runKey($portfolio), now()->toIso8601String(), now()->addDay());

        $this->settle($portfolio);
        $this->expire($portfolio);
        $this->registerSells($portfolio);

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
     * Purchases the mode made today. Only buys count: the sale at the target is
     * registered with each purchase and is part of the same operation.
     */
    public function openedToday(Portfolio $portfolio): int
    {
        return Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('reason', 'micro')
            ->where('side', OrderSide::Buy)
            ->whereIn('status', [OrderStatus::Filled, OrderStatus::PartiallyFilled])
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
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

            // The cycle's own sale: another cycle's resting sell of the same pair may have filled meanwhile.
            $sales = Order::query()
                ->where('portfolio_id', $portfolio->id)
                ->where('asset_id', $cycle->asset_id)
                ->where('side', OrderSide::Sell)
                ->where('status', OrderStatus::Filled)
                ->where('filled_at', '>=', $cycle->opened_at)
                ->latest('filled_at');

            $sell = (clone $sales)->where('position_id', $cycle->position_id)->first()
                ?? $sales->whereNull('position_id')->first();

            // Closed with no sale in the app (the coins left the account): no exit price nor result to invent.
            $exitPrice = $sell?->price;
            $fees = $cycle->fees + ($sell->fees ?? 0);
            $net = $exitPrice === null ? null : $cycle->qty * ($exitPrice - $cycle->entry_price) - $fees;

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
                'net_pnl' => $net === null ? null : round($net, 8),
            ]);
        }
    }

    /**
     * Sell, at a net profit, the positions of the assets the owner marked to
     * liquidate. A real portfolio needs the automatic execution confirmed with
     * CONFIRMO, as for any order the mode sends on its own.
     *
     * @return int positions sold
     */
    public function liquidate(Portfolio $portfolio): int
    {
        if (($portfolio->micro_liquidate_asset_ids ?? []) === [] || ! $portfolio->isRunning()) {
            return 0;
        }

        if ($portfolio->isLive() && ! $portfolio->autoExecutesLive()) {
            return 0;
        }

        $sold = 0;
        $positions = $portfolio->openPositions()->whereIn('asset_id', $portfolio->micro_liquidate_asset_ids)->with(['asset', 'portfolio'])->get();

        foreach ($positions as $position) {
            $row = $this->liquidation($portfolio, $position);

            if ($row['price'] === null || $row['price'] < $row['sell_from']) {
                continue;
            }

            if ($portfolio->isLive()) {
                $this->refreshFilters($position->asset);
                $position->asset->refresh();
            }

            if ($this->executor->closePosition($position, 'micro_liquidate', self::ACTOR)?->status === OrderStatus::Filled) {
                $sold++;
                AuditLog::record(self::ACTOR, 'micro.liquidated', $position, ['portfolio_id' => $portfolio->id, 'symbol' => $position->asset->symbol, 'cost' => $row['cost'], 'price' => $row['price']]);
            }
        }

        $this->settle($portfolio);

        return $sold;
    }

    /**
     * Where a marked position stands: its cost, the price that pays the cost
     * plus the round trip (the sale only goes from there) and the last price.
     *
     * @return array{cost: float, sell_from: float, price: float|null}
     */
    public function liquidation(Portfolio $portfolio, Position $position): array
    {
        $cost = $this->costBasis($portfolio, $position);

        return [
            'cost' => $cost,
            'sell_from' => $cost * (1 + $this->costs->roundTripPct() / 100),
            'price' => $this->prices->quote($position->asset)?->price,
        ];
    }

    /**
     * What the coins cost. A position the account sync recreated carries the
     * price of the moment it was read, not what was paid, so the open cycle's
     * entry or the latest purchases that add up to the quantity come first.
     */
    private function costBasis(Portfolio $portfolio, Position $position): float
    {
        $entry = $portfolio->microCycles()->where('position_id', $position->id)->where('status', 'open')->value('entry_price');

        if ($entry !== null) {
            return (float) $entry;
        }

        $buys = Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('asset_id', $position->asset_id)
            ->where('side', OrderSide::Buy)
            ->where('status', OrderStatus::Filled)
            ->latest('filled_at')
            ->limit(20)
            ->get();

        $qty = 0.0;
        $paid = 0.0;

        foreach ($buys as $buy) {
            if ($buy->price === null) {
                continue;
            }

            $take = min((float) $buy->qty, $position->qty - $qty);
            $qty += $take;
            $paid += $take * (float) $buy->price;

            if ($qty >= $position->qty - 1e-9) {
                break;
            }
        }

        return $qty > 0 ? $paid / $qty : (float) $position->avg_price;
    }

    /**
     * Every open position of a tracked pair keeps a limit sell resting at the
     * exchange: the cycle's own at its target, and a position the account sync
     * brought (or whose sale was refused) at what was paid plus the round trip
     * plus the card's net margin. A refusal is shown on the card and tried
     * again: the next minute, then spaced up to 15 minutes while the exchange
     * keeps refusing, so a stuck order does not send one alert a minute.
     *
     * @return int sells registered
     */
    public function registerSells(Portfolio $portfolio): int
    {
        if ($this->sellBlock($portfolio) !== null) {
            return 0;
        }

        $gross = $this->costs->grossMarginPct($portfolio->micro_net_margin_pct);
        $placed = 0;

        $positions = $portfolio->openPositions()->with(['asset', 'portfolio'])->get()
            ->filter(fn (Position $position) => $this->sellSkip($portfolio, $position) === null);

        foreach ($positions as $position) {
            if ($this->executor->restingSells($position)->isNotEmpty()) {
                Cache::forget($this->sellFailureKey($position));

                continue;
            }

            $failure = Cache::get($this->sellFailureKey($position));

            if (is_array($failure) && now()->lt($failure['retry_at'])) {
                continue;
            }

            if ($portfolio->isLive()) {
                $this->refreshFilters($position->asset);
                $position->asset->refresh();
            }

            $cycle = $portfolio->microCycles()->where('position_id', $position->id)->where('status', 'open')->first();
            $price = $this->plannedSellPrice($portfolio, $position, $gross);

            $sell = $this->executor->placeRestingSell($position, $price, 'target', self::ACTOR);

            if (! is_string($sell) && $sell->status !== OrderStatus::Rejected) {
                Cache::forget($this->sellFailureKey($position));
                AuditLog::record(self::ACTOR, 'micro.target_order_placed', $cycle ?? $position, ['portfolio_id' => $portfolio->id, 'order_id' => $sell->id, 'price' => $sell->price, 'qty' => $sell->qty, 'retry' => true]);
                $placed++;

                continue;
            }

            // Nothing reached the exchange when the check failed here: the next minute may already pass.
            $sent = ! is_string($sell);
            $attempts = $sent ? (($failure['attempts'] ?? 0) + 1) : 0;
            $wait = $sent ? min(2 ** ($attempts - 1), 15) : 1;

            Cache::put($this->sellFailureKey($position), [
                'reason' => is_string($sell) ? $sell : 'corretora recusou: '.($sell->reject_reason ?? 'sem motivo informado'),
                'at' => now()->toIso8601String(),
                'retry_at' => now()->addMinutes($wait)->subSeconds(5),
                'attempts' => $attempts,
            ], now()->addDay());
        }

        return $placed;
    }

    /**
     * Why no sale is registered for any position of the portfolio right now, or null.
     */
    private function sellBlock(Portfolio $portfolio): ?string
    {
        return match (true) {
            ! $portfolio->micro_enabled => 'Micro operações desligadas: a venda só é registrada com o modo ligado.',
            ! $portfolio->isRunning() => 'Carteira fora do período de execução.',
            $this->halt->isHalted() => 'Parada geral ligada: a venda é registrada no minuto seguinte ao desligamento.',
            $portfolio->isOrdersBlocked() => '"Parar tudo" ligado nesta carteira.',
            $portfolio->isLive() && ! $this->live->enabled() => 'Execução real desligada no servidor.',
            $portfolio->isLive() && ! $portfolio->autoExecutesLive() => 'Carteira real sem a execução automática confirmada (CONFIRMO).',
            default => null,
        };
    }

    /**
     * Why the mode does not keep a sale registered for this position, or null
     * when it does. The card shows it next to a position without a sale.
     */
    public function sellSkip(Portfolio $portfolio, Position $position): ?string
    {
        if (($block = $this->sellBlock($portfolio)) !== null) {
            return $block;
        }

        if ($position->signal_id !== null) {
            return 'Posição aberta por sinal dos agentes: sai pelo stop e pelo alvo do sinal.';
        }

        if ($portfolio->liquidates($position->asset_id)) {
            return 'Marcado em "Liquidar com lucro": vende quando o preço cobrir o custo.';
        }

        $tracked = $portfolio->assets()->pluck('assets.id');

        if ($tracked->isNotEmpty() && ! $tracked->contains($position->asset_id)) {
            return 'Ativo fora dos "Ativos acompanhados" da carteira.';
        }

        return null;
    }

    /**
     * Where the position is meant to be sold: the open cycle's target, or what
     * was paid plus the round trip plus the card's net margin.
     */
    public function plannedSellPrice(Portfolio $portfolio, Position $position, ?float $grossPct = null): float
    {
        $target = $portfolio->microCycles()->where('position_id', $position->id)->where('status', 'open')->value('target_price');

        return $target !== null
            ? (float) $target
            : $this->costBasis($portfolio, $position) * (1 + ($grossPct ?? $this->costs->grossMarginPct($portfolio->micro_net_margin_pct)) / 100);
    }

    /**
     * The owner's "Liquidar agora": cancel the resting sale and sell the whole
     * position at market, even at a loss. It goes through the executor, so the
     * risk guard, the live switch and "Parar tudo" still apply; the click and
     * its confirmation are the owner's approval of this one order.
     *
     * @return Order|string the filled sale, or why it did not go out
     */
    public function liquidateNow(Portfolio $portfolio, Position $position, string $actor): Order|string
    {
        if ($position->closed_at !== null || $position->portfolio_id !== $portfolio->id) {
            return 'A posição já foi encerrada.';
        }

        $position->loadMissing(['asset', 'portfolio']);

        if ($portfolio->isLive()) {
            $this->refreshFilters($position->asset);
            $position->asset->refresh();
        }

        $row = $this->liquidation($portfolio, $position);

        // Checked before the resting sale is cancelled: below the pair's minimum it would be cancelled for nothing.
        if (($small = $this->executor->belowMinimum($position, $row['price'])) !== null) {
            return $small;
        }

        $sale = $this->executor->closePosition($position, 'micro_manual', $actor, $blocked);

        if ($sale === null) {
            return $blocked ?? 'A venda não saiu.';
        }

        if ($sale->status !== OrderStatus::Filled) {
            return 'Corretora recusou: '.($sale->reject_reason ?? 'sem motivo informado');
        }

        $this->settle($portfolio);
        Cache::forget($this->sellFailureKey($position));

        AuditLog::record($actor, 'micro.liquidated_now', $position, [
            'portfolio_id' => $portfolio->id,
            'symbol' => $position->asset->symbol,
            'cost' => $row['cost'],
            'price' => $sale->price,
            'qty' => $sale->qty,
            'net_pnl' => $sale->price === null ? null : round((float) $sale->qty * ((float) $sale->price - $row['cost']) - (float) $sale->fees, 8),
        ]);

        return $sale;
    }

    /**
     * Why the sale of the position could not be registered, and when it is tried again.
     *
     * @return array{reason: string, at: string, retry_at: string}|null
     */
    public function sellFailure(Position $position): ?array
    {
        $failure = Cache::get($this->sellFailureKey($position));

        return is_array($failure)
            ? ['reason' => $failure['reason'], 'at' => $failure['at'], 'retry_at' => $failure['retry_at']->toIso8601String()]
            : null;
    }

    private function sellFailureKey(Position $position): string
    {
        return 'micro:sell-failure:'.$position->id;
    }

    /**
     * Sell what stayed open longer than the limit: a cycle is meant to be quick.
     */
    private function expire(Portfolio $portfolio): void
    {
        // Each cycle keeps the time limit of the rule that opened it.
        $cycles = $portfolio->microCycles()
            ->where('status', 'open')
            ->where('opened_at', '<=', now()->subMinutes(min((int) config('trading.micro.max_hold_minutes'), (int) config('trading.micro.trend.max_hold_minutes'))))
            ->with('position.asset')
            ->get()
            ->filter(fn (MicroCycle $cycle) => $cycle->opened_at->lte(now()->subMinutes($cycle->max_hold_minutes ?? (int) config('trading.micro.max_hold_minutes'))));

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
        if ($portfolio->liquidates($asset->id)) {
            return 'marcado para liquidar com lucro: o modo não compra mais este par.';
        }

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

        $budget = $this->walletBudget($portfolio);

        if ($portfolio->micro_order_value > $budget['available'] + 0.0001) {
            return sprintf('o limite de %d%% do saldo da carteira para micro operações está em uso (%.2f de %.2f USDT); uma nova ordem de %.2f espera uma venda devolver saldo.', $budget['pct'], $budget['used'], $budget['limit'], $portfolio->micro_order_value);
        }

        $decision = $this->risk->check($portfolio, $this->buyRequest($portfolio, $asset, $setup, $this->costs->grossMarginPct($portfolio->micro_net_margin_pct)), ownerSized: true);

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
            return sprintf('a ordem de %.2f USDT seria reduzida para %.2f USDT, abaixo do mínimo de %.2f USDT, e não é enviada. %s Falta saldo disponível ou o teto real por ordem está baixo.', $portfolio->micro_order_value, $value, $minimum, $decision->summary());
        }

        return null;
    }

    /**
     * How much of the wallet's USDT the mode may keep in open cycles: the chosen
     * share of (free cash + what the open cycles hold). A sale gives the value
     * back, so the next purchase can go out.
     *
     * @return array{pct: int, limit: float, used: float, available: float}
     */
    public function walletBudget(Portfolio $portfolio): array
    {
        $used = (float) $portfolio->microCycles()->where('status', 'open')->get()->sum(fn ($cycle) => $cycle->qty * $cycle->entry_price);
        $pct = $portfolio->micro_wallet_pct ?? 100;
        $cash = (float) Portfolio::query()->whereKey($portfolio->id)->value('cash');
        $limit = ($cash + $used) * $pct / 100;

        return ['pct' => $pct, 'limit' => $limit, 'used' => $used, 'available' => max($limit - $used, 0.0)];
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
            stopPrice: $setup->price * (1 - $this->rules->exits($setup, $grossPct)['stop_pct'] / 100),
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

        $exits = $this->rules->exits($setup, $grossPct);
        $entry = $outcome->price ?? $setup->price;
        $target = $entry * (1 + $exits['target_pct'] / 100);
        $stop = $entry * (1 - $exits['stop_pct'] / 100);

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
            'gross_margin_pct' => $exits['target_pct'],
            'rule' => $portfolio->micro_rule,
            'max_hold_minutes' => $exits['max_hold_minutes'],
            'analysis' => $setup->toArray(),
            'opened_at' => now(),
        ]);

        AuditLog::record(self::ACTOR, 'micro.opened', $cycle, ['portfolio_id' => $portfolio->id, 'symbol' => $asset->symbol, 'order_id' => $outcome->id]);

        // The sale at the target is registered at once and rests until it executes.
        // Without it the cycle keeps the old exit: the target checked every minute.
        $sell = $this->executor->placeRestingSell($position->refresh(), $target, 'target', self::ACTOR);

        if (is_string($sell) || $sell->status === OrderStatus::Rejected) {
            AuditLog::record(self::ACTOR, 'micro.target_order_failed', $cycle, ['portfolio_id' => $portfolio->id, 'reason' => is_string($sell) ? $sell : $sell->reject_reason]);
            // The next minute's run tries again (registerSells) and the card shows why it failed.
            Cache::put($this->sellFailureKey($position), [
                'reason' => is_string($sell) ? $sell : 'corretora recusou: '.($sell->reject_reason ?? 'sem motivo informado'),
                'at' => now()->toIso8601String(),
                'retry_at' => now()->addMinute()->subSeconds(5),
                'attempts' => is_string($sell) ? 0 : 1,
            ], now()->addDay());
        } else {
            AuditLog::record(self::ACTOR, 'micro.target_order_placed', $cycle, ['portfolio_id' => $portfolio->id, 'order_id' => $sell->id, 'price' => $sell->price, 'qty' => $sell->qty]);
        }

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
