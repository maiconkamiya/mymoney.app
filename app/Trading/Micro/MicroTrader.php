<?php

namespace App\Trading\Micro;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\MarketData\BinanceSymbols;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Candle;
use App\Models\MicroCycle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Trading\Contracts\PriceFeed;
use App\Trading\Data\OrderRequest;
use App\Trading\Live\LiveLimits;
use App\Trading\OrderExecutor;
use App\Trading\PortfolioValuation;
use App\Trading\Risk\RiskGuard;
use App\Trading\TradingHalt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the "Micro operações" mode of one portfolio, once a minute: closes the
 * books of finished cycles, forces out the ones held too long, and opens new
 * ones on the most volatile pairs whose chart allows an entry.
 *
 * The buy is a limit order at the suggested price (MicroEntryPrice), valid
 * for the seconds chosen on the card: the cycle waits as "pending" until it
 * executes, and is dropped when it expires unfilled (a market buy remains as
 * an option on the card). Target and stop count from the price really paid.
 *
 * Right after the buy a limit sell rests at the target on the exchange; the
 * regular exits job books its fill, and the stop or the time limit cancel it
 * before selling at market. With "Stop na corretora" the exit is an OCO: the
 * stop rests at the exchange too, and the app sells at market only when the
 * price falls past the stop leg's limit. Every order goes through the executor, so the
 * risk guard, the live caps and "Parar tudo" apply as for any other order.
 */
class MicroTrader
{
    public const ACTOR = 'micro';

    private const REALTIME_KEY = 'micro:realtime:alive';

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
        private readonly MicroEntryPrice $entryPrice,
        private readonly PortfolioValuation $valuation,
    ) {}

    /**
     * @return int number of cycles opened
     */
    public function run(Portfolio $portfolio): int
    {
        // The owner's "liquidar com lucro" works even with the mode off: it only sells.
        $this->liquidate($portfolio);

        // Books the limit buys that executed and cancels the expired ones, even with the mode off.
        $this->pendingBuys($portfolio);

        if (! $portfolio->micro_enabled) {
            return 0;
        }

        Cache::put($this->runKey($portfolio), now()->toIso8601String(), now()->addDay());

        $this->settle($portfolio);
        $this->expire($portfolio);
        $this->registerSells($portfolio);

        $state = $this->state($portfolio);
        $this->recordDrawdownBlock($portfolio, $state);

        if ($state['code'] !== 'active') {
            return 0;
        }

        $gross = $this->costs->grossMarginPct($portfolio->micro_net_margin_pct);
        $open = $portfolio->microCycles()->whereIn('status', ['open', 'pending'])->count();
        $waiting = $portfolio->microCycles()->where('status', 'pending')->count();
        $opened = 0;

        $blocks = [];

        foreach ($this->ranker->rank($portfolio, $gross) as $row) {
            if ($portfolio->micro_daily_operations !== null && $this->openedToday($portfolio) + $waiting >= $portfolio->micro_daily_operations) {
                break;
            }

            if (! $row['setup']->entry) {
                continue;
            }

            // The ranking read every pair before this one: read this pair again so the buy goes out on the chart of now (#196).
            $readAt = microtime(true);
            $setup = $this->ranker->reread($portfolio, $row['asset'], $gross);

            if ($setup === null || ! $setup->entry) {
                $blocks[] = $row['asset']->symbol.': o gráfico mudou na releitura antes do envio e não pede mais entrada.';

                continue;
            }

            $reason = $this->gate($portfolio, $row['asset'], $setup, $open);

            if ($reason !== null) {
                $blocks[] = $row['asset']->symbol.': '.$reason;

                continue;
            }

            Cache::forget($this->blockKey($portfolio));

            if ($this->open($portfolio, $row['asset'], $setup, $gross, $readAt)) {
                $open++;
                $opened++;
                $waiting = $portfolio->microCycles()->where('status', 'pending')->count();
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

        if ($this->halt->blocks($portfolio) || $portfolio->isOrdersBlocked()) {
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

        $target = $this->dailyTargetValue($portfolio);

        if ($target !== null && $this->pnlToday($portfolio) >= $target) {
            return ['code' => 'target_hit', 'label' => 'Meta do dia batida'];
        }

        // O mesmo limite da guarda de risco: com ele batido, nenhuma compra passaria.
        if ($this->risk->dailyLossReached($portfolio)) {
            return ['code' => 'loss_limit', 'label' => sprintf('Perda máxima do dia atingida (%s%% do saldo)', self::pct($this->risk->dailyLossLimit($portfolio)))];
        }

        // A queda máxima desde o maior saldo, que a guarda confere logo depois da perda do dia (#216).
        $drawdown = $this->drawdown($portfolio);

        if ($drawdown['blocked']) {
            return ['code' => 'drawdown_limit', 'label' => sprintf('Bloqueado pela queda de %s%% desde o maior saldo (limite %s%%)', self::pct($drawdown['drop']), self::pct($drawdown['limit']))];
        }

        return ['code' => 'active', 'label' => 'Operando'];
    }

    /**
     * Queda do patrimônio desde o maior saldo, na mesma conta da guarda de risco (RiskGuard::check):
     * com ela no limite do perfil, nenhuma compra passa. Libera quando o patrimônio passa de
     * `release_equity` (ou, na simulada, com um novo aporte, que entra no saldo e no maior saldo).
     *
     * @return array{drop: float, limit: float, equity: float, peak: float, release_equity: float, blocked: bool}
     */
    public function drawdown(Portfolio $portfolio): array
    {
        $equity = $this->valuation->equity($portfolio);
        $peak = $this->valuation->peakEquity($portfolio);
        $drop = 1 - $equity / max($peak, 0.00000001);
        $limit = (float) $portfolio->riskProfile->max_drawdown_pct;

        return [
            'drop' => $drop,
            'limit' => $limit,
            'equity' => $equity,
            'peak' => $peak,
            'release_equity' => $peak * (1 - $limit),
            'blocked' => $drop >= $limit,
        ];
    }

    /**
     * Grava na auditoria quando a queda máxima começa a barrar as compras e quando deixa de barrar,
     * uma vez por mudança: o motor roda a cada poucos segundos. O último estado fica no cache e,
     * sem ele (cache limpo no deploy), vem do último registro.
     *
     * @param  array{code: string, label: string}  $state
     */
    private function recordDrawdownBlock(Portfolio $portfolio, array $state): void
    {
        // "Operando" já passou pela queda; outro motivo à frente dela pode escondê-la, então confere.
        $drawdown = $state['code'] === 'active' ? null : $this->drawdown($portfolio);
        $blocked = $drawdown !== null && $drawdown['blocked'];
        $key = 'micro:drawdown_blocked:'.$portfolio->id;
        $known = Cache::get($key);

        if ($known === null) {
            $known = AuditLog::query()
                ->where('subject_type', $portfolio->getMorphClass())
                ->where('subject_id', $portfolio->id)
                ->whereIn('action', ['micro.drawdown_blocked', 'micro.drawdown_released'])
                ->latest('id')
                ->value('action') === 'micro.drawdown_blocked' ? 1 : 0;
        }

        if ((bool) $known !== $blocked) {
            $drawdown ??= $this->drawdown($portfolio);

            AuditLog::record(self::ACTOR, $blocked ? 'micro.drawdown_blocked' : 'micro.drawdown_released', $portfolio, [
                'portfolio_id' => $portfolio->id,
                'drop_pct' => round(100 * $drawdown['drop'], 2),
                'limit_pct' => round(100 * $drawdown['limit'], 2),
                'equity' => round($drawdown['equity'], 4),
                'peak' => round($drawdown['peak'], 4),
                'release_equity' => round($drawdown['release_equity'], 4),
            ]);
        }

        Cache::put($key, $blocked ? 1 : 0, now()->addDay());
    }

    /**
     * Fração em porcentagem para o texto do estado: 0.101 vira "10,1".
     */
    private static function pct(float $fraction): string
    {
        return rtrim(rtrim(number_format(100 * $fraction, 2, ',', ''), '0'), ',');
    }

    /**
     * Meta do dia em USDT: a % escolhida sobre o saldo do início do dia.
     */
    public function dailyTargetValue(Portfolio $portfolio): ?float
    {
        return $portfolio->micro_daily_target_pct === null
            ? null
            : $portfolio->micro_daily_target_pct / 100 * $this->valuation->startOfDayEquity($portfolio);
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
        $cycles = $portfolio->microCycles()->where('status', 'open')->with(['position', 'asset'])->get();

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

            if ($sell !== null) {
                $exitPrice = $sell->price;
                $fees = $cycle->fees + $sell->fees;
                $source = 'venda';
            } else {
                // Closed with no sale in the app (the coins left the account, #208): the price of a sale made
                // on Binance itself, or else the last price when the account sync saw the coins gone. The
                // result is an estimate, never a silent zero; the sale fee is the usual rate.
                [$exitPrice, $source] = $this->outsideExit($portfolio, $cycle, $position);
                $fees = $cycle->fees + ($exitPrice === null ? 0.0 : $cycle->qty * $exitPrice * (float) config('trading.costs.crypto.fee_rate'));
            }

            $net = $exitPrice === null ? null : $cycle->qty * ($exitPrice - $cycle->entry_price) - $fees;

            $cycle->update([
                'status' => 'closed',
                'exit_price' => $exitPrice,
                'exit_source' => $exitPrice === null ? null : $source,
                'fees' => $fees,
                'net_pnl' => $net,
                'exit_reason' => $position->exit_reason ?? 'sold',
                'closed_at' => $position->closed_at,
            ]);

            AuditLog::record(self::ACTOR, 'micro.closed', $cycle, [
                'portfolio_id' => $portfolio->id,
                'exit_reason' => $cycle->exit_reason,
                'exit_source' => $cycle->exit_source,
                'net_pnl' => $net === null ? null : round($net, 8),
            ]);
        }
    }

    /**
     * Exit price of a cycle whose coins left the account with no sale in the app:
     * a filled sale of the pair made on Binance outside the app since the cycle
     * opened (read by the account sync), or else the last 1 minute close up to
     * the moment the position closed.
     *
     * @return array{0: float|null, 1: string|null} the price and where it came from ("binance" or "cotacao")
     */
    private function outsideExit(Portfolio $portfolio, MicroCycle $cycle, Position $position): array
    {
        $symbol = $cycle->asset->symbol;
        $closedAt = $position->closed_at ?? now();

        $sale = collect($portfolio->sync_report['orders'] ?? [])
            ->filter(fn (array $row) => ($row['symbol'] ?? null) === $symbol
                && ($row['side'] ?? null) === 'sell'
                && in_array($row['status'] ?? null, ['filled', 'partially_filled'], true)
                && ! ($row['from_app'] ?? false)
                && ($row['avg_price'] ?? null) !== null
                && isset($row['time'])
                && Carbon::parse($row['time'])->betweenIncluded($cycle->opened_at, $closedAt))
            ->sortByDesc('time')
            ->first();

        if ($sale !== null) {
            return [(float) $sale['avg_price'], 'binance'];
        }

        $close = Candle::query()
            ->where('asset_id', $cycle->asset_id)
            ->where('timeframe', '1m')
            ->where('open_at', '<=', $closedAt)
            ->latest('open_at')
            ->value('close');

        $price = $close !== null ? (float) $close : $this->prices->quote($cycle->asset)?->price;

        return $price === null ? [null, null] : [$price, 'cotacao'];
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

            $failure = $this->storedSellFailure($position);

            if ($failure !== null && now()->lt(Carbon::parse($failure['retry_at']))) {
                continue;
            }

            if ($portfolio->isLive()) {
                $this->refreshFilters($position->asset);
                $position->asset->refresh();
            }

            $cycle = $portfolio->microCycles()->where('position_id', $position->id)->where('status', 'open')->first();
            $price = $this->plannedSellPrice($portfolio, $position, $gross);

            $sell = $this->placeExit($portfolio, $position, $cycle, $price);

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
                'retry_at' => now()->addMinutes($wait)->subSeconds(5)->toIso8601String(),
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
            $this->halt->blocks($portfolio) => 'Parada geral ligada: a venda é registrada no minuto seguinte ao desligamento.',
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

        if ($position->asset->tradingEnded()) {
            return 'Fim de negociação do par: o app vende a posição a mercado.';
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
        $failure = $this->storedSellFailure($position);

        return $failure !== null
            ? ['reason' => $failure['reason'], 'at' => $failure['at'], 'retry_at' => $failure['retry_at']]
            : null;
    }

    /**
     * The failure kept in the cache, with every date as ISO text. Anything else
     * (an entry from before 0.3.75 kept a Carbon object, which the cache no longer
     * unserializes) is forgotten and treated as no failure: the sale is tried again.
     *
     * @return array{reason: string, at: string, retry_at: string, attempts: int}|null
     */
    private function storedSellFailure(Position $position): ?array
    {
        $failure = Cache::get($this->sellFailureKey($position));

        if ($failure === null) {
            return null;
        }

        if (is_array($failure)
            && is_string($failure['reason'] ?? null)
            && is_string($failure['at'] ?? null)
            && is_string($failure['retry_at'] ?? null)
            && strtotime($failure['retry_at']) !== false) {
            return [
                'reason' => $failure['reason'],
                'at' => $failure['at'],
                'retry_at' => $failure['retry_at'],
                'attempts' => is_int($failure['attempts'] ?? null) ? $failure['attempts'] : 0,
            ];
        }

        Cache::forget($this->sellFailureKey($position));

        return null;
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
        if ($asset->tradingEnded()) {
            return 'negociação encerrada em '.$asset->trading_ends_on?->format('d/m/Y').': o modo não compra mais este par.';
        }

        if ($portfolio->liquidates($asset->id)) {
            return 'marcado para liquidar com lucro: o modo não compra mais este par.';
        }

        if ($openCycles >= (int) config('trading.micro.max_open_cycles')) {
            return 'já há '.$openCycles.' ciclos abertos (máximo '.(int) config('trading.micro.max_open_cycles').').';
        }

        $pending = $portfolio->microCycles()->where('asset_id', $asset->id)->where('status', 'pending')->first(['expires_at']);

        if ($pending !== null) {
            return 'já tem uma compra limitada esperando neste par'.($pending->expires_at !== null ? ' (vale até '.$pending->expires_at->format('H:i:s').').' : '.');
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

        $gross = $this->costs->grossMarginPct($portfolio->micro_net_margin_pct);
        $stopPct = $this->rules->exits($setup, $gross)['stop_pct'];
        $price = $this->entryLimit($portfolio, $asset, $setup) ?? $setup->price;
        $cover = $this->stopCoverValue($portfolio, $asset, $stopPct, $price);

        // The sale at the stop must still reach the pair's minimum, or the stop could not sell (#175, #200).
        if ($cover > $portfolio->micro_order_value * 1.1) {
            return sprintf('aporte não cobre o stop: para a venda no stop de %.2f%% ainda valer o mínimo de %.2f USDT do par, a compra precisa de %.2f USDT, mais de 10%% acima do aporte de %.2f.', $stopPct, (float) $asset->min_notional, $cover, $portfolio->micro_order_value);
        }

        $decision = $this->risk->check($portfolio, $this->buyRequest($portfolio, $asset, $setup, $gross, $this->entryLimit($portfolio, $asset, $setup)), ownerSized: true);

        if (! $decision->allowed) {
            return 'guarda de risco: '.$decision->summary();
        }

        $wanted = $this->entryQty($portfolio, $asset, $price, $stopPct) * $price;
        $minimum = max(self::minOrderValue($portfolio), $portfolio->isLive() ? (float) $asset->min_notional : 0.0);

        if ($wanted > $portfolio->micro_order_value * 1.1) {
            return sprintf('o passo de quantidade do par (%s) obriga uma ordem de %.2f USDT, acima do aporte de %.2f.', (float) $asset->lot_size, $wanted, $portfolio->micro_order_value);
        }

        $value = ($decision->order->qty ?? 0) * $price;

        if ($value < $minimum * 0.99) {
            return sprintf('a ordem de %.2f USDT seria reduzida para %.2f USDT, abaixo do mínimo de %.2f USDT, e não é enviada. %s Falta saldo disponível ou o teto real por ordem está baixo.', $portfolio->micro_order_value, $value, $minimum, $decision->summary());
        }

        if ($value < $cover - 1e-9) {
            return sprintf('aporte não cobre o stop: a ordem seria reduzida para %.2f USDT, e a venda no stop de %.2f%% ficaria abaixo do mínimo de %.2f USDT do par. %s', $value, $stopPct, (float) $asset->min_notional, $decision->summary());
        }

        return null;
    }

    /**
     * How much of the wallet's USDT the mode may keep in open cycles: the chosen
     * share of (free cash + what the open cycles hold). A sale gives the value
     * back, so the next purchase can go out. A limit buy still waiting counts
     * as used; on a real portfolio Binance also holds its USDT out of the free
     * balance, so it is added back to the base there.
     *
     * @return array{pct: int, limit: float, used: float, available: float}
     */
    public function walletBudget(Portfolio $portfolio): array
    {
        $cycles = $portfolio->microCycles()->whereIn('status', ['open', 'pending'])->get(['status', 'qty', 'entry_price']);
        $held = (float) $cycles->where('status', 'open')->sum(fn ($cycle) => $cycle->qty * $cycle->entry_price);
        $waiting = (float) $cycles->where('status', 'pending')->sum(fn ($cycle) => $cycle->qty * $cycle->entry_price);
        $used = $held + $waiting;
        $pct = $portfolio->micro_wallet_pct ?? 100;
        $cash = (float) Portfolio::query()->whereKey($portfolio->id)->value('cash');
        $limit = ($cash + $held + ($portfolio->isLive() ? $waiting : 0.0)) * $pct / 100;

        return ['pct' => $pct, 'limit' => $limit, 'used' => $used, 'available' => max($limit - $used, 0.0)];
    }

    /**
     * The quantity that fits the pair's filters: rounded UP to the step so the
     * value never falls under the minimum order (a floor to the step turned
     * 5 USDT into 4.9 and the exchange refused it), and at least the pair's
     * minimum quantity and, on a real portfolio, its minimum order value plus
     * a 1% margin for the price moving before the fill, and enough for the
     * sale at the stop to still reach that minimum (see stopCoverValue).
     */
    private function entryQty(Portfolio $portfolio, Asset $asset, float $price, float $stopPct): float
    {
        $value = $portfolio->micro_order_value;

        if ($portfolio->isLive()) {
            $value = max($value, (float) $asset->min_notional * 1.01, $this->stopCoverValue($portfolio, $asset, $stopPct, $price));
        }

        $qty = max($value / $price, (float) $asset->min_qty);
        $step = (float) $asset->lot_size;

        return $step > 0 ? ceil(round($qty / $step, 8)) * $step : $qty;
    }

    /**
     * Smallest purchase whose sale at the stop, after the price slips past it,
     * still reaches the pair's minimum order. What can be sold is less than
     * what was bought: Binance takes the fee in the bought coin (1.04 PROM
     * became 1.03896) and the sale is rounded down to the pair's step (1.03),
     * so value × (1 − fee) × (1 − stop − slippage) − one step ≥ min_notional.
     * Below it Binance refuses the market sale and the stop cannot sell (#200).
     * Zero on a simulated portfolio, which has no such minimum.
     */
    public function stopCoverValue(Portfolio $portfolio, Asset $asset, float $stopPct, float $price): float
    {
        $minimum = (float) $asset->min_notional;

        if (! $portfolio->isLive() || $minimum <= 0) {
            return 0.0;
        }

        $kept = (1 - ($stopPct + (float) config('trading.micro.stop_slippage_pct')) / 100)
            * (1 - (float) config('trading.costs.crypto.fee_rate'));

        if ($kept <= 0) {
            return INF;
        }

        return ($minimum + (float) $asset->lot_size * $price) / $kept;
    }

    /**
     * What the card and "Posições abertas" show for a real position that is
     * worth less than the pair's minimum now: the app cannot sell it at market.
     */
    public function belowMinimumNotice(Position $position, ?float $price): ?string
    {
        return $this->executor->belowMinimum($position, $price) !== null
            ? 'Abaixo do mínimo da Binance: o app não consegue vender; saída só pelo alvo ou pela conversão de saldo pequeno na Binance.'
            : null;
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

    /**
     * The price the engine suggests paying for the pair now, and how the buy goes out.
     *
     * @return array{mode: 'limit'|'market', price: float, reference: float, discount_pct: float, seconds: int|null}
     */
    public function suggestion(Portfolio $portfolio, Asset $asset, MicroSetup $setup): array
    {
        $quote = $this->prices->quote($asset)?->price;
        $price = $this->entryPrice->suggest($setup, $asset, $quote);
        $limit = $portfolio->micro_entry_mode !== 'market';

        return [
            'mode' => $limit ? 'limit' : 'market',
            'price' => $price,
            'reference' => $quote !== null && $quote > 0 ? min($setup->price, $quote) : $setup->price,
            'discount_pct' => round($this->entryPrice->discountPct($setup, $price, $quote), 4),
            'seconds' => $limit ? $this->limitSeconds($portfolio) : null,
        ];
    }

    /**
     * The limit of the buy, or null when the owner chose to buy at market.
     */
    private function entryLimit(Portfolio $portfolio, Asset $asset, MicroSetup $setup): ?float
    {
        return $portfolio->micro_entry_mode === 'market' ? null : $this->suggestion($portfolio, $asset, $setup)['price'];
    }

    private function limitSeconds(Portfolio $portfolio): int
    {
        return max((int) config('trading.micro.limit.min_seconds'), min((int) config('trading.micro.limit.max_seconds'), $portfolio->micro_limit_seconds ?? 60));
    }

    private function buyRequest(Portfolio $portfolio, Asset $asset, MicroSetup $setup, float $grossPct, ?float $limit = null): OrderRequest
    {
        $stopPct = $this->rules->exits($setup, $grossPct)['stop_pct'];
        $price = $limit ?? $setup->price;

        return new OrderRequest(
            asset: $asset,
            side: OrderSide::Buy,
            qty: $this->entryQty($portfolio, $asset, $price, $stopPct),
            type: $limit === null ? OrderType::Market : OrderType::Limit,
            limitPrice: $limit,
            stopPrice: $price * (1 - $stopPct / 100),
            // Seconds too: the real-time engine may buy the same pair again within a minute.
            clientId: 'micro-'.$portfolio->id.'-'.$asset->id.'-'.now()->format('YmdHis'),
            // The limit rests on the book: it never executes on arrival, above the suggested price.
            postOnly: $limit !== null,
        );
    }

    /**
     * Send the buy. A limit buy leaves the cycle "pending" until it executes;
     * a market buy (or a limit that executed at once) starts it right away.
     */
    private function open(Portfolio $portfolio, Asset $asset, MicroSetup $setup, float $grossPct, ?float $readAt = null): bool
    {
        $readAt ??= microtime(true);
        $suggestion = $this->suggestion($portfolio, $asset, $setup);
        $isLimit = $portfolio->micro_entry_mode !== 'market';
        $sentAt = microtime(true);
        $outcome = $this->executor->placeDirect($portfolio, $this->buyRequest($portfolio, $asset, $setup, $grossPct, $isLimit ? $suggestion['price'] : null), self::ACTOR, 'micro');
        $answeredAt = microtime(true);

        if (! is_string($outcome)) {
            $this->recordLatency($portfolio, $asset, $setup, $suggestion, $outcome, $readAt, $sentAt, $answeredAt);
        }
        // A market buy must fill at once; a limit buy may also rest, waiting for the price.
        $accepted = ! is_string($outcome) && ($outcome->status === OrderStatus::Filled || ($isLimit && $outcome->status === OrderStatus::Pending));

        if (is_string($outcome) || ! $accepted) {
            Cache::put($this->blockKey($portfolio), $asset->symbol.': '.(is_string($outcome) ? $outcome : 'corretora recusou: '.$outcome->reject_reason), now()->addHour());

            return false;
        }

        $exits = $this->rules->exits($setup, $grossPct);
        // Until the buy executes the cycle carries the limit (or the reference price) as its entry.
        $reference = $isLimit ? $suggestion['price'] : $setup->price;

        $cycle = $portfolio->microCycles()->create([
            'asset_id' => $asset->id,
            'status' => 'pending',
            'qty' => $outcome->qty,
            'entry_price' => $reference,
            'target_price' => $reference * (1 + $exits['target_pct'] / 100),
            'stop_price' => $reference * (1 - $exits['stop_pct'] / 100),
            'fees' => 0,
            'gross_margin_pct' => $exits['target_pct'],
            'rule' => $portfolio->micro_rule,
            'max_hold_minutes' => $exits['max_hold_minutes'],
            'analysis' => $setup->toArray() + ['suggestion' => $suggestion],
            'suggested_price' => $suggestion['price'],
            'buy_order_id' => $outcome->id,
            'expires_at' => $isLimit ? now()->addSeconds($suggestion['seconds'] ?? 60) : null,
            'opened_at' => now(),
        ]);

        if ($outcome->status === OrderStatus::Pending) {
            AuditLog::record(self::ACTOR, 'micro.limit_placed', $cycle, ['portfolio_id' => $portfolio->id, 'symbol' => $asset->symbol, 'order_id' => $outcome->id, 'price' => $suggestion['price'], 'reference' => $suggestion['reference'], 'expires_at' => $cycle->expires_at?->toIso8601String()]);

            return true;
        }

        return $this->activate($portfolio, $cycle, $outcome);
    }

    /**
     * How long the buy took from the chart reading to the exchange's answer,
     * and where the price was at each step, so `micro:latency` can measure in
     * production whether the price runs away before the order arrives (#196).
     *
     * @param  array{mode: 'limit'|'market', price: float, reference: float, discount_pct: float, seconds: int|null}  $suggestion
     */
    private function recordLatency(Portfolio $portfolio, Asset $asset, MicroSetup $setup, array $suggestion, Order $order, float $readAt, float $sentAt, float $answeredAt): void
    {
        AuditLog::record(self::ACTOR, 'micro.buy_latency', $order, [
            'portfolio_id' => $portfolio->id,
            'symbol' => $asset->symbol,
            'mode' => $suggestion['mode'],
            'status' => $order->status->value,
            // Reading, risk checks and the suggested price, before the order leaves.
            'decision_ms' => (int) round(($sentAt - $readAt) * 1000),
            // Risk guard of the executor, the request to the exchange and its answer.
            'send_ms' => (int) round(($answeredAt - $sentAt) * 1000),
            'price_read' => $setup->price,
            'limit_price' => $suggestion['mode'] === 'limit' ? $suggestion['price'] : null,
            'price_answered' => $this->prices->quote($asset)?->price,
            'paid' => $order->status === OrderStatus::Filled ? (float) $order->price : null,
        ]);
    }

    /**
     * Look at the limit buys still waiting: book the ones that executed and
     * start their cycle, cancel the ones whose validity ran out (or that the
     * mode may no longer keep: switched off, "Parar tudo", day closed). An
     * expired buy with nothing executed leaves no cycle, only the cancelled
     * order and its log. The real-time engine asks this every second.
     *
     * @return int cycles started
     */
    public function pendingBuys(Portfolio $portfolio): int
    {
        $cycles = $portfolio->microCycles()->where('status', 'pending')->with(['buyOrder.asset', 'asset'])->get();

        if ($cycles->isEmpty()) {
            return 0;
        }

        $state = $this->state($portfolio)['code'];
        // The pending buy itself counts against the real daily cap: that one does not cancel it.
        $drop = ! in_array($state, ['active', 'live_cap'], true);
        $started = 0;

        foreach ($cycles as $cycle) {
            $order = $cycle->buyOrder;

            if ($order === null) {
                $cycle->delete();

                continue;
            }

            $order->setRelation('portfolio', $portfolio);

            if (! $this->executor->refreshRestingBuy($order, self::ACTOR)) {
                if (! $drop && $cycle->expires_at !== null && now()->lt($cycle->expires_at)) {
                    continue;
                }

                // The exchange did not confirm the cancel: the next pass tries again.
                if (! $this->executor->cancelRestingBuy($order, self::ACTOR)) {
                    continue;
                }
            }

            $order->refresh();

            if ((float) $order->fills()->sum('qty') <= 0) {
                AuditLog::record(self::ACTOR, 'micro.limit_expired', $order, [
                    'portfolio_id' => $portfolio->id,
                    'symbol' => $cycle->asset->symbol,
                    'price' => $order->price,
                    'status' => $order->status->value,
                    'reason' => $drop ? $state : 'validade',
                ]);
                $cycle->delete();

                continue;
            }

            if ($this->activate($portfolio, $cycle, $order)) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * Start the cycle of a buy that executed: target and stop from the price
     * really paid, then the sale at the target. A limit that executed only in
     * part on a real portfolio is completed at market when what it bought
     * could not be sold (under the pair's minimum, or the stop's cover).
     */
    private function activate(Portfolio $portfolio, MicroCycle $cycle, Order $buy): bool
    {
        $asset = $cycle->asset;

        /** @var Position|null $position */
        $position = $portfolio->openPositions()->where('asset_id', $asset->id)->latest('id')->first();

        if ($position === null) {
            AuditLog::record(self::ACTOR, 'micro.start_failed', $cycle, ['portfolio_id' => $portfolio->id, 'order_id' => $buy->id, 'reason' => 'compra executada sem posição aberta']);
            $cycle->delete();

            return false;
        }

        $stopPct = $cycle->entry_price > 0 ? (1 - $cycle->stop_price / $cycle->entry_price) * 100 : 0.0;
        $fees = (float) $buy->fees;

        if ($buy->status !== OrderStatus::Filled && $portfolio->isLive()) {
            $minimum = max((float) $asset->min_notional, $this->stopCoverValue($portfolio, $asset, $stopPct, (float) $position->avg_price));
            $rest = $cycle->qty - $position->qty;

            if ($position->qty * (float) $position->avg_price < $minimum && $rest > 0) {
                $step = (float) $asset->lot_size;
                $topUp = $this->executor->placeDirect($portfolio, new OrderRequest(
                    asset: $asset,
                    side: OrderSide::Buy,
                    qty: $step > 0 ? ceil(round($rest / $step, 8)) * $step : $rest,
                    stopPrice: $cycle->stop_price,
                    clientId: 'micro-'.$portfolio->id.'-'.$asset->id.'-'.now()->format('YmdHis').'r',
                ), self::ACTOR, 'micro');

                AuditLog::record(self::ACTOR, 'micro.limit_completed', $cycle, ['portfolio_id' => $portfolio->id, 'order_id' => is_string($topUp) ? null : $topUp->id, 'outcome' => is_string($topUp) ? $topUp : $topUp->status->value]);

                if (! is_string($topUp) && $topUp->status === OrderStatus::Filled) {
                    $fees += (float) $topUp->fees;
                    $position->refresh();
                }
            }
        }

        $entry = (float) $position->avg_price;
        $target = $entry * (1 + $cycle->gross_margin_pct / 100);
        $stop = $entry * (1 - $stopPct / 100);

        $position->update(['target_price' => $target, 'stop_price' => $stop]);

        $cycle->update([
            'status' => 'open',
            'position_id' => $position->id,
            'qty' => $position->qty,
            'entry_price' => $entry,
            'target_price' => $target,
            'stop_price' => $stop,
            'fees' => $fees,
            'expires_at' => null,
            // The time limit counts from the fill, not from when the limit was placed.
            'opened_at' => now(),
        ]);

        AuditLog::record(self::ACTOR, 'micro.opened', $cycle, ['portfolio_id' => $portfolio->id, 'symbol' => $asset->symbol, 'order_id' => $buy->id, 'suggested_price' => $cycle->suggested_price, 'entry_price' => $entry]);

        // The sale at the target is registered at once and rests until it executes.
        // Without it the cycle keeps the old exit: the target checked every minute.
        $sell = $this->placeExit($portfolio, $position->refresh(), $cycle, $target);

        if (is_string($sell) || $sell->status === OrderStatus::Rejected) {
            AuditLog::record(self::ACTOR, 'micro.target_order_failed', $cycle, ['portfolio_id' => $portfolio->id, 'reason' => is_string($sell) ? $sell : $sell->reject_reason]);
            // The next minute's run tries again (registerSells) and the card shows why it failed.
            Cache::put($this->sellFailureKey($position), [
                'reason' => is_string($sell) ? $sell : 'corretora recusou: '.($sell->reject_reason ?? 'sem motivo informado'),
                'at' => now()->toIso8601String(),
                'retry_at' => now()->addMinute()->subSeconds(5)->toIso8601String(),
                'attempts' => is_string($sell) ? 0 : 1,
            ], now()->addDay());
        } else {
            AuditLog::record(self::ACTOR, 'micro.target_order_placed', $cycle, ['portfolio_id' => $portfolio->id, 'order_id' => $sell->id, 'price' => $sell->price, 'qty' => $sell->qty]);
        }

        return true;
    }

    /**
     * Register the cycle's exit at the exchange. With "Stop na corretora" it
     * is an OCO (the target and the stop together); when the OCO does not fit
     * the pair or the exchange refuses it, the exit falls back to the limit
     * sale at the target with the stop watched by the app, and the log says why.
     *
     * @return Order|string the sale at the target, or why it did not go out
     */
    private function placeExit(Portfolio $portfolio, Position $position, ?MicroCycle $cycle, float $target): Order|string
    {
        if ($cycle !== null && $portfolio->micro_exchange_stop) {
            $oco = $this->executor->placeRestingOco($position, $target, (float) ($position->stop_price ?? $cycle->stop_price), self::ACTOR);

            if (is_array($oco)) {
                AuditLog::record(self::ACTOR, 'micro.oco_placed', $cycle, ['portfolio_id' => $portfolio->id, 'target_order_id' => $oco['target']->id, 'stop_order_id' => $oco['stop']->id, 'group' => $oco['target']->oco_group, 'target' => $oco['target']->price, 'stop' => $oco['stop']->stop_price, 'stop_limit' => $oco['stop']->price, 'qty' => $oco['target']->qty]);

                return $oco['target'];
            }

            AuditLog::record(self::ACTOR, 'micro.oco_failed', $cycle, ['portfolio_id' => $portfolio->id, 'reason' => $oco]);
        }

        return $this->executor->placeRestingSell($position, $target, 'target', self::ACTOR);
    }

    /**
     * Positions of open cycles whose price reached the stop or the target (the
     * resting sale may have executed). The real-time engine asks this every
     * second; the time limit and the books are left to the whole run.
     *
     * @return list<int>
     */
    public function due(Portfolio $portfolio): array
    {
        $due = [];

        $cycles = $portfolio->microCycles()->where('status', 'open')->with('position.asset')->get();

        foreach ($cycles as $cycle) {
            $position = $cycle->position;

            if ($position === null || $position->closed_at !== null) {
                continue;
            }

            $price = $this->prices->quote($position->asset)?->price;

            if ($price === null) {
                continue;
            }

            if ($price <= ($position->stop_price ?? $cycle->stop_price) || $price >= ($position->target_price ?? $cycle->target_price)) {
                $due[] = $position->id;
            }
        }

        return $due;
    }

    /**
     * The real-time engine (`micro:watch`) says it is alive on every pass.
     */
    public static function markRealtime(): void
    {
        Cache::put(self::REALTIME_KEY, now()->toIso8601String(), now()->addMinutes(5));
    }

    /**
     * Whether the real-time engine passed recently; while it does, the minute run stays as a reserve.
     */
    public static function realtimeAlive(): bool
    {
        $at = Cache::get(self::REALTIME_KEY);

        return is_string($at) && strtotime($at) !== false
            && Carbon::parse($at)->gte(now()->subSeconds((int) config('trading.micro.realtime.alive_seconds')));
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
