<?php

namespace App\Trading\Live;

use App\Enums\Market;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Order;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Trading\Contracts\PriceFeed;
use App\Trading\OrderExecutor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mirrors a user's Binance spot account into their "Conta Binance" portfolio:
 * free USDT becomes the cash and every coin with a USDT pair becomes an open position (dust and unpriced coins are
 * listed in the sync report instead),
 * so the agents see (and size orders against) what the account really holds.
 * Before reading balances it books the app's resting sells that Binance already
 * executed, and it keeps the last orders of each pair of the portfolio (from the
 * app or placed by hand) for the owner to see.
 * It only reads: orders still go through the Broker and the live guard rails.
 */
class BinanceAccountSync
{
    public const QUOTE = 'USDT';

    /** Positions worth less than this (in USDT) are dust and not tracked. */
    public const DUST = 1.0;

    /** Orders kept per pair, newest first. */
    public const ORDERS_PER_PAIR = 20;

    /** Pairs read per sync: each read weighs 20 on the account's request limit. */
    public const MAX_ORDER_PAIRS = 25;

    public function __construct(
        private readonly BinanceAccountReader $reader,
        private readonly PriceFeed $prices,
        private readonly OrderExecutor $executor,
    ) {}

    /**
     * The user's own Binance portfolio, created on first use.
     */
    public function portfolioFor(BrokerCredential $credential): Portfolio
    {
        $existing = Portfolio::query()
            ->where('user_id', $credential->user_id)
            ->where('broker', 'binance')
            ->where('mode', PortfolioMode::Live)
            ->first();

        if ($existing !== null) {
            $existing->update(['broker_credential_id' => $credential->id, 'status' => PortfolioStatus::Running]);

            return $existing;
        }

        return Portfolio::create([
            'user_id' => $credential->user_id,
            'risk_profile_id' => RiskProfile::firstOrCreate(['name' => 'Padrão'])->id,
            'broker_credential_id' => $credential->id,
            'name' => 'Conta Binance',
            'market' => Market::Crypto,
            'mode' => PortfolioMode::Live,
            'broker' => 'binance',
            'status' => PortfolioStatus::Running,
            'initial_cash' => 0,
            'cash' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addYears(5),
            'auto_execute' => false,
        ]);
    }

    /**
     * @return array{cash: float, positions: int, ignored: list<string>, orders: int, settled: int}
     *
     * @throws LiveTradingException
     */
    public function sync(Portfolio $portfolio): array
    {
        $credential = $portfolio->brokerCredential;

        if ($credential === null || $credential->broker !== 'binance' || $credential->user_id !== $portfolio->user_id) {
            throw new LiveTradingException('A carteira não tem uma chave da Binance do próprio dono.');
        }

        if ($credential->can_withdraw || $credential->verified_at === null) {
            throw new LiveTradingException('Chave não verificada ou com permissão de saque.');
        }

        // A sale that executed on Binance closes its position with the real result before the balances say it is gone.
        $settled = $this->executor->refreshPortfolio($portfolio, 'sync');

        $client = new BinanceClient($credential);
        $balances = $this->reader->balances($client);
        $cash = $balances[self::QUOTE] ?? 0.0;
        $tickers = $this->tickers();
        $assets = Asset::query()->where('market', Market::Crypto)->get()->keyBy('symbol');

        $holdings = [];
        $held = [];

        foreach ($balances as $coin => $qty) {
            if ($coin === self::QUOTE) {
                continue;
            }

            $asset = $assets->get($coin.self::QUOTE);
            $feedPrice = $asset === null || ! $asset->active ? null : $this->prices->quote($asset)?->price;
            $price = $feedPrice ?? $this->price($coin, $tickers);
            $value = $price === null ? null : $qty * $price;
            $row = ['coin' => $coin, 'qty' => $qty, 'price' => $price, 'value' => $value];

            if ($price === null) {
                $holdings[] = $row + ['status' => 'sem_cotacao', 'reason' => 'Sem par de negociação com USDT, BTC, BNB ou BRL para estimar o valor.'];
            } elseif ($value < self::DUST) {
                $holdings[] = $row + ['status' => 'poeira', 'reason' => 'Valor abaixo de 1 USDT.'];
            } elseif ($asset === null && ! isset($tickers[$coin.self::QUOTE])) {
                $holdings[] = $row + ['status' => 'sem_par_usdt', 'reason' => 'Sem par em USDT: o valor é estimado por outro par e a moeda não é negociada pelo app.'];
            } else {
                $asset ??= Asset::create([
                    'symbol' => $coin.self::QUOTE,
                    'market' => Market::Crypto,
                    'name' => $coin,
                    'quote_currency' => self::QUOTE,
                    'lot_size' => '0.00000001',
                    'tick_size' => '0.00000001',
                    'active' => false,
                ]);
                $held[$asset->id] = [$asset, $qty, $price];
                $holdings[] = $row + ['status' => 'posicao', 'reason' => null];
            }
        }

        // Optional: a key or testnet without the wallet endpoint still syncs the Spot balances.
        try {
            $wallets = $this->reader->wallets($client);
        } catch (Throwable) {
            $wallets = null;
        }

        [$orders, $orderErrors] = $this->recentOrders($client, $portfolio, array_values(array_map(fn (array $row) => $row[0]->symbol, $held)));

        DB::transaction(function () use ($portfolio, $cash, $held, $holdings, $orders, $orderErrors, $wallets) {
            // The scheduled sync and the "Sincronizar" button can run together: one at a time per portfolio.
            Portfolio::query()->whereKey($portfolio->id)->lockForUpdate()->first();

            $open = $portfolio->openPositions()->get()->keyBy('asset_id');

            // A micro limit buy still resting books its own fill: the coins it bought are not read as a new position.
            $buying = Order::query()->where('portfolio_id', $portfolio->id)->where('side', OrderSide::Buy)->where('type', OrderType::Limit)->where('status', OrderStatus::Pending)->pluck('asset_id')->all();

            foreach ($held as $assetId => [$asset, $qty, $price]) {
                $position = $open->get($assetId);

                if (in_array($assetId, $buying, true)) {
                    continue;
                }

                if ($position === null) {
                    // O custo real não vem da conta: o preço de agora é o melhor ponto de partida.
                    $portfolio->positions()->create(['asset_id' => $asset->id, 'qty' => $qty, 'avg_price' => $price, 'opened_at' => now()]);
                } else {
                    $position->update(['qty' => $qty]);
                }
            }

            // A position with a limit sell still resting is not closed by a balance read.
            $resting = Order::query()->where('portfolio_id', $portfolio->id)->where('side', OrderSide::Sell)->where('type', OrderType::Limit)->where('status', OrderStatus::Pending)->pluck('asset_id')->all();

            $kept = [...array_keys($held), ...$resting];

            // Filter by asset: an Eloquent collection's except() drops by the position's own id, not by the keyBy key,
            // which closed every position still in the account and reopened it on the next sync.
            $open->reject(fn (Position $position) => in_array($position->asset_id, $kept, true))->each(fn (Position $position) => $position->update([
                'closed_at' => now(),
                'exit_reason' => 'conta',
            ]));

            $portfolio->update(['cash' => $cash, 'synced_at' => now(), 'sync_report' => ['holdings' => $holdings, 'orders' => $orders, 'order_errors' => $orderErrors, 'wallets' => $wallets]]);
        });

        if ($portfolio->initial_cash <= 0) {
            $portfolio->refresh();
            $portfolio->update(['initial_cash' => max(round($cash + $portfolio->openPositions()->get()->sum(fn (Position $p) => $p->qty * $p->avg_price), 8), 0.01)]);
        }

        $ignored = array_values(array_map(fn (array $row) => $row['coin'], array_filter($holdings, fn (array $row) => $row['status'] !== 'posicao')));

        return ['cash' => $cash, 'positions' => count($held), 'ignored' => $ignored, 'orders' => count($orders), 'settled' => $settled];
    }

    /**
     * The last orders on Binance of every pair of the portfolio: what it holds,
     * what it tracks and what the app traded in the last week. A pair that
     * fails is reported and skipped, so the balances still sync.
     *
     * @param  list<string>  $heldSymbols
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function recentOrders(BinanceClient $client, Portfolio $portfolio, array $heldSymbols): array
    {
        $local = Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNotNull('external_id')
            ->where('created_at', '>=', now()->subDays(7))
            ->with('asset')
            ->get();

        $symbols = collect($heldSymbols)
            ->merge($portfolio->openPositions()->with('asset')->get()->map(fn (Position $position) => $position->asset->symbol))
            ->merge($portfolio->assets()->pluck('assets.symbol'))
            ->merge($local->map(fn (Order $order) => $order->asset->symbol))
            ->filter(fn (string $symbol) => str_ends_with($symbol, self::QUOTE))
            ->unique()
            ->sort()
            ->take(self::MAX_ORDER_PAIRS)
            ->values();

        $known = Order::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereNotNull('external_id')
            ->whereIn('asset_id', Asset::query()->whereIn('symbol', $symbols)->select('id'))
            ->pluck('id', 'external_id');

        $orders = [];
        $errors = [];

        foreach ($symbols as $symbol) {
            try {
                $rows = $this->reader->orders($client, $symbol, self::ORDERS_PER_PAIR);
            } catch (Throwable $e) {
                $errors[$symbol] = $e->getMessage();

                continue;
            }

            foreach ($rows as $row) {
                $orders[] = $this->orderRow($symbol, $row, $known->all());
            }
        }

        usort($orders, fn (array $a, array $b) => strcmp((string) $b['time'], (string) $a['time']));

        return [$orders, $errors];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $known  app order id by external id
     * @return array<string, mixed>
     */
    private function orderRow(string $symbol, array $row, array $known): array
    {
        $externalId = $symbol.':'.$row['orderId'];
        $executed = (float) ($row['executedQty'] ?? 0);
        $quote = (float) ($row['cummulativeQuoteQty'] ?? 0);
        $limit = (float) ($row['price'] ?? 0);
        $clientId = (string) ($row['clientOrderId'] ?? '');
        $appOrderId = $known[$externalId] ?? null;

        return [
            'id' => $externalId,
            'symbol' => $symbol,
            'side' => strtolower((string) ($row['side'] ?? '')),
            'type' => strtolower((string) ($row['type'] ?? '')),
            'status' => match ($row['status'] ?? '') {
                'FILLED' => 'filled',
                'PARTIALLY_FILLED' => 'partially_filled',
                'CANCELED', 'PENDING_CANCEL' => 'cancelled',
                'EXPIRED', 'EXPIRED_IN_MATCH' => 'expired',
                'REJECTED' => 'rejected',
                default => 'pending',
            },
            'price' => $limit > 0 ? $limit : null,
            'avg_price' => $executed > 0 ? round($quote / $executed, 8) : null,
            'qty' => (float) ($row['origQty'] ?? 0),
            'executed_qty' => $executed,
            'value' => round($quote, 8),
            'time' => isset($row['time']) ? Carbon::createFromTimestampMs((int) $row['time'])->toIso8601String() : null,
            'app_order_id' => $appOrderId,
            'from_app' => $appOrderId !== null || preg_match('/^(signal|position|micro)-/', $clientId) === 1,
        ];
    }

    /**
     * Last price of every Binance pair, from the public endpoint. Failing is fine:
     * assets with candles are still priced by the feed.
     *
     * @return array<string, float>
     */
    private function tickers(): array
    {
        try {
            $rows = Http::baseUrl(config('trading.binance.base_url'))->timeout(10)->get('/api/v3/ticker/price')->throw()->json();
        } catch (Throwable) {
            return [];
        }

        $prices = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (isset($row['symbol'], $row['price'])) {
                $prices[(string) $row['symbol']] = (float) $row['price'];
            }
        }

        return $prices;
    }

    /**
     * USDT value of one unit of `$coin`: direct pair first, then through BTC, BNB or BRL.
     *
     * @param  array<string, float>  $tickers
     */
    private function price(string $coin, array $tickers): ?float
    {
        if (isset($tickers[$coin.self::QUOTE])) {
            return $tickers[$coin.self::QUOTE];
        }

        foreach (['BTC', 'BNB'] as $bridge) {
            if (isset($tickers[$coin.$bridge], $tickers[$bridge.self::QUOTE])) {
                return $tickers[$coin.$bridge] * $tickers[$bridge.self::QUOTE];
            }
        }

        if (isset($tickers[$coin.'BRL'], $tickers['USDTBRL']) && $tickers['USDTBRL'] > 0) {
            return $tickers[$coin.'BRL'] / $tickers['USDTBRL'];
        }

        return null;
    }
}
