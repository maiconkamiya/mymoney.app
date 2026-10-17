<?php

namespace App\Trading\Live;

use App\Enums\Market;
use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Trading\Contracts\PriceFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mirrors a user's Binance spot account into their "Conta Binance" portfolio:
 * free USDT becomes the cash and every coin with a USDT pair becomes an open position (dust and unpriced coins are
 * listed in the sync report instead),
 * so the agents see (and size orders against) what the account really holds.
 * It only reads: orders still go through the Broker and the live guard rails.
 */
class BinanceAccountSync
{
    public const QUOTE = 'USDT';

    /** Positions worth less than this (in USDT) are dust and not tracked. */
    public const DUST = 1.0;

    public function __construct(
        private readonly BinanceAccountReader $reader,
        private readonly PriceFeed $prices,
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
     * @return array{cash: float, positions: int, ignored: list<string>}
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

        $balances = $this->reader->balances(new BinanceClient($credential));
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

        DB::transaction(function () use ($portfolio, $cash, $held, $holdings) {
            $open = $portfolio->openPositions()->get()->keyBy('asset_id');

            foreach ($held as $assetId => [$asset, $qty, $price]) {
                $position = $open->get($assetId);

                if ($position === null) {
                    // O custo real não vem da conta: o preço de agora é o melhor ponto de partida.
                    $portfolio->positions()->create(['asset_id' => $asset->id, 'qty' => $qty, 'avg_price' => $price, 'opened_at' => now()]);
                } else {
                    $position->update(['qty' => $qty]);
                }
            }

            $open->except(array_keys($held))->each(fn (Position $position) => $position->update([
                'closed_at' => now(),
                'exit_reason' => 'conta',
            ]));

            $portfolio->update(['cash' => $cash, 'synced_at' => now(), 'sync_report' => ['holdings' => $holdings]]);
        });

        if ($portfolio->initial_cash <= 0) {
            $portfolio->refresh();
            $portfolio->update(['initial_cash' => max(round($cash + $portfolio->openPositions()->get()->sum(fn (Position $p) => $p->qty * $p->avg_price), 8), 0.01)]);
        }

        $ignored = array_values(array_map(fn (array $row) => $row['coin'], array_filter($holdings, fn (array $row) => $row['status'] !== 'posicao')));

        return ['cash' => $cash, 'positions' => count($held), 'ignored' => $ignored];
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
