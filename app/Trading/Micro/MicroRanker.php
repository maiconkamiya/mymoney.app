<?php

namespace App\Trading\Micro;

use App\Enums\Market;
use App\Enums\MicroRule;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\Portfolio;
use Illuminate\Support\Collection;

/**
 * Orders the crypto assets the portfolio tracks by recent volatility (average 1-minute range) so
 * the trader looks first at the pairs that move the most, and reads each one.
 */
class MicroRanker
{
    public function __construct(private readonly MicroRules $rules) {}

    /**
     * @return Collection<int, array{asset: Asset, setup: MicroSetup}> most volatile first
     */
    public function rank(Portfolio $portfolio, float $grossMarginPct): Collection
    {
        return $this->evaluate($portfolio, $grossMarginPct)['ready']->take((int) config('trading.micro.ranking_size'))->values();
    }

    /**
     * Reads every asset the portfolio tracks. `ready` has the ones with a chart
     * reading, most volatile first; `skipped` says why the others have none.
     *
     * @return array{ready: Collection<int, array{asset: Asset, setup: MicroSetup}>, skipped: list<array{symbol: string, reason: string}>}
     */
    public function evaluate(Portfolio $portfolio, float $grossMarginPct): array
    {
        $maxAge = (int) config('trading.micro.max_candle_age_seconds');
        $rule = $portfolio->micro_rule;
        $lookback = $this->rules->lookbackMinutes($rule);
        $skipped = [];

        $ready = $this->assets($portfolio)
            ->map(function (Asset $asset) use ($grossMarginPct, $maxAge, $rule, $lookback, &$skipped) {
                $setup = $this->readAsset($asset, $rule, $lookback, $maxAge, $grossMarginPct);

                if (is_string($setup)) {
                    $skipped[] = ['symbol' => $asset->symbol, 'reason' => $setup];

                    return null;
                }

                return ['asset' => $asset, 'setup' => $setup];
            })
            ->filter()
            ->sortByDesc(fn (array $row) => $row['setup']->atrPct)
            ->values();

        return ['ready' => $ready, 'skipped' => $skipped];
    }

    /**
     * Reads one pair again from the freshest candles, so the buy goes out on the
     * chart of now and not on the one read before every other pair (#196).
     * Null when the pair has no reading any more.
     */
    public function reread(Portfolio $portfolio, Asset $asset, float $grossMarginPct): ?MicroSetup
    {
        $setup = $this->readAsset($asset, $portfolio->micro_rule, $this->rules->lookbackMinutes($portfolio->micro_rule), (int) config('trading.micro.max_candle_age_seconds'), $grossMarginPct);

        return is_string($setup) ? null : $setup;
    }

    /**
     * The chart reading of one pair, or why it has none.
     */
    private function readAsset(Asset $asset, MicroRule $rule, int $lookback, int $maxAge, float $grossMarginPct): MicroSetup|string
    {
        $candles = Candle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', '1m')
            ->where('open_at', '>=', now()->subMinutes($lookback))
            ->orderBy('open_at')
            ->get();

        if ($candles->isEmpty()) {
            return 'Sem candles de 1 minuto nas últimas horas (coletor da Binance parado?).';
        }

        if ($candles->last()->open_at->copy()->addMinute()->diffInSeconds(now(), false) > $maxAge) {
            return 'Candles atrasados: o último tem mais de '.$maxAge.' segundos.';
        }

        $rows = [];

        foreach ($candles as $candle) {
            $rows[] = ['high' => (float) $candle->high, 'low' => (float) $candle->low, 'close' => (float) $candle->close, 'volume' => (float) $candle->volume];
        }

        return $this->rules->read($rule, $rows, $grossMarginPct) ?? match ($rule) {
            MicroRule::Scalp => 'Poucos candles para ler o gráfico (precisa de 40 minutos).',
            MicroRule::TrendPullback => 'Poucos candles para ler o gráfico (a regra de tendência precisa de 22 horas de velas de 1 minuto).',
            MicroRule::ScalpUptrend => 'Poucos candles para ler o gráfico (a alta em 24 h precisa de 24 horas de velas de 1 minuto).',
            MicroRule::Breakout, MicroRule::BreakoutPressure => 'Poucos candles para ler o gráfico (as regras de rompimento precisam de 2 h e meia de velas de 1 minuto).',
        };
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assets(Portfolio $portfolio): Collection
    {
        $tracked = $portfolio->assets()->pluck('assets.id');

        // Sem ativos marcados na carteira, ela acompanha todos, menos os achados só pelo explorador.
        return Asset::query()
            ->when($tracked->isNotEmpty(), fn ($query) => $query->whereIn('id', $tracked), fn ($query) => $query->where('discovered', false))
            ->where('market', Market::Crypto)
            ->where('active', true)
            ->where('is_benchmark', false)
            ->tradable()
            ->orderBy('symbol')
            ->get();
    }
}
