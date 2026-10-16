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

/**
 * Mirrors a user's Binance spot account into their "Conta Binance" portfolio:
 * free USDT becomes the cash and every tracked asset becomes an open position,
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
        $assets = Asset::query()
            ->where('market', Market::Crypto)
            ->where('active', true)
            ->where('quote_currency', self::QUOTE)
            ->get()
            ->keyBy('symbol');

        $ignored = [];
        $held = [];

        foreach ($balances as $coin => $qty) {
            if ($coin === self::QUOTE) {
                continue;
            }

            $asset = $assets->get($coin.self::QUOTE);
            $price = $asset === null ? null : $this->prices->quote($asset)?->price;

            if ($asset === null || $price === null || $qty * $price < self::DUST) {
                $ignored[] = $coin;

                continue;
            }

            $held[$asset->id] = [$asset, $qty, $price];
        }

        DB::transaction(function () use ($portfolio, $cash, $held) {
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

            $portfolio->update(['cash' => $cash]);
        });

        if ($portfolio->initial_cash <= 0) {
            $portfolio->refresh();
            $portfolio->update(['initial_cash' => max(round($cash + $portfolio->openPositions()->get()->sum(fn (Position $p) => $p->qty * $p->avg_price), 8), 0.01)]);
        }

        return ['cash' => $cash, 'positions' => count($held), 'ignored' => $ignored];
    }
}
