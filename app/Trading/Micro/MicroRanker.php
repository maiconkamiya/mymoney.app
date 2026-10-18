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
                $candles = Candle::query()
                    ->where('asset_id', $asset->id)
                    ->where('timeframe', '1m')
                    ->where('open_at', '>=', now()->subMinutes($lookback))
                    ->orderBy('open_at')
                    ->get();

                if ($candles->isEmpty()) {
                    $skipped[] = ['symbol' => $asset->symbol, 'reason' => 'Sem candles de 1 minuto nas últimas horas (coletor da Binance parado?).'];

                    return null;
                }

                if ($candles->last()->open_at->copy()->addMinute()->diffInSeconds(now(), false) > $maxAge) {
                    $skipped[] = ['symbol' => $asset->symbol, 'reason' => 'Candles atrasados: o último tem mais de '.$maxAge.' segundos.'];

                    return null;
                }

                $rows = [];

                foreach ($candles as $candle) {
                    $rows[] = ['high' => (float) $candle->high, 'low' => (float) $candle->low, 'close' => (float) $candle->close];
                }

                $setup = $this->rules->read($rule, $rows, $grossMarginPct);

                if ($setup === null) {
                    $skipped[] = ['symbol' => $asset->symbol, 'reason' => $rule === MicroRule::Scalp
                        ? 'Poucos candles para ler o gráfico (precisa de 40 minutos).'
                        : 'Poucos candles para ler o gráfico (a regra de tendência precisa de 22 horas de velas de 1 minuto).'];

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
            ->orderBy('symbol')
            ->get();
    }
}
