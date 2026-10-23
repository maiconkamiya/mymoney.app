<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\MicroRule;
use App\Models\Asset;
use App\Models\Candle;
use App\Models\MicroBacktest;
use App\Trading\Micro\MicroBacktester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('micro:backtest
    {--rule=all : scalp (regra atual), trend_pullback (tendência com recuo), scalp_uptrend (atual + alta em 24 h e 4 h), breakout (rompimento confirmado), breakout_pressure (pressão antes do rompimento), scalp_filtered (atual + alta + RSI e volume), scalp_30m (atual em velas de 30 minutos), scalp_week (atual + semana de 7 dias) ou all}
    {--symbols= : pares separados por vírgula (padrão: todos os de cripto ativos)}
    {--days=30 : dias de velas de 1 minuto até agora}
    {--net-margin=0.2 : margem líquida da regra atual, em %}
    {--fee= : taxa por lado, ex. 0.00075 com desconto do BNB (padrão: trading.costs.crypto)}
    {--slippage= : deslize por lado em pontos-base (padrão: trading.costs.crypto)}
    {--entry=limit : limit (compra limitada no preço sugerido, padrão do motor) ou market (compra a mercado)}
    {--limit-seconds=60 : validade da compra limitada; o backtest usa velas de 1 minuto inteiras (arredonda para cima)}
    {--save : grava o resultado para o card das micro operações}')]
#[Description('Replay the micro operations rules over the stored 1-minute candles, with costs, and check them on a validation period')]
class BacktestMicroOperations extends Command
{
    public function handle(MicroBacktester $backtester): int
    {
        $option = $this->option('rule');
        $rules = $option === 'all' ? MicroRule::cases() : [is_string($option) ? MicroRule::tryFrom($option) : null];

        if (in_array(null, $rules, true)) {
            $this->error('Regra desconhecida. Use scalp, trend_pullback, scalp_uptrend, breakout, breakout_pressure, scalp_filtered, scalp_30m, scalp_week ou all.');

            return self::INVALID;
        }

        if (! in_array($this->option('entry'), ['limit', 'market'], true)) {
            $this->error('Entrada desconhecida. Use limit ou market.');

            return self::INVALID;
        }

        $costs = config('trading.costs.crypto');
        $params = [
            'days' => max(1, (int) $this->option('days')),
            'net_margin_pct' => (float) $this->option('net-margin'),
            'fee_rate' => $this->option('fee') !== null ? (float) $this->option('fee') : (float) $costs['fee_rate'],
            'slippage_bps' => $this->option('slippage') !== null ? (float) $this->option('slippage') : (float) $costs['slippage_bps'],
            'cooldown_minutes' => (int) config('trading.micro.cooldown_minutes'),
            'entry' => (string) $this->option('entry'),
            'limit_minutes' => max(1, (int) ceil((int) $this->option('limit-seconds') / 60)),
            'validation_share' => (float) config('trading.micro.backtest.validation_share'),
            'min_trades' => (int) config('trading.micro.backtest.min_trades'),
        ];

        $series = $this->candles($params['days']);

        if ($series === []) {
            $this->warn('Sem velas de 1 minuto no período. Rode o backfill (binance:backfill) ou aumente --days.');

            return self::FAILURE;
        }

        $this->line($params['entry'] === 'limit'
            ? sprintf('Entrada: compra limitada no preço sugerido, válida por %d vela(s) de 1 minuto; executa se a mínima tocar o limite. Na vela da execução só o stop conta.', $params['limit_minutes'])
            : 'Entrada: compra a mercado na abertura da vela seguinte, com deslize.');

        $this->line(sprintf(
            'Período: %d dias, %d par(es). Custo da ida e volta: %.2f%% (taxa %.3f%% por lado, deslize %.1f pb).',
            $params['days'], count($series), 2 * $params['fee_rate'] * 100 + 2 * $params['slippage_bps'] / 100, $params['fee_rate'] * 100, $params['slippage_bps'],
        ));

        foreach ($rules as $rule) {
            $result = $this->backtest($backtester, $rule, $series, $params);
            $this->report($rule, $result);

            if ($this->option('save')) {
                MicroBacktest::create(['rule' => $rule, 'params' => $params + ['symbols' => array_keys($series)], 'summary' => $result, 'passed' => $result['passed']]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  non-empty-array<string, non-empty-list<array{t: int, open: float, high: float, low: float, close: float, volume: float}>>  $series
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function backtest(MicroBacktester $backtester, MicroRule $rule, array $series, array $params): array
    {
        $options = ['net_margin_pct' => $params['net_margin_pct'], 'fee_rate' => $params['fee_rate'], 'slippage_bps' => $params['slippage_bps'], 'cooldown_minutes' => $params['cooldown_minutes'], 'entry' => $params['entry'], 'limit_minutes' => $params['limit_minutes']];
        $start = min(array_map(fn (array $candles) => $candles[0]['t'], $series));
        $end = max(array_map(fn (array $candles) => $candles[count($candles) - 1]['t'], $series));
        $boundary = (int) ($start + ($end - $start) * (1 - $params['validation_share']));

        $all = [];
        $random = [];
        $pairs = [];
        $unfilled = 0;

        foreach ($series as $symbol => $candles) {
            $trades = $backtester->simulate($rule, $candles, $options);
            $unfilled += $backtester->lastUnfilled();
            $pairs[$symbol] = $backtester->summarize($trades);
            $all = [...$all, ...$trades];
            $random = [...$random, ...$backtester->simulate($rule, $candles, $options + ['random' => true, 'seed' => crc32($symbol)])];
        }

        $fit = array_values(array_filter($all, fn (array $trade) => $trade['opened_at'] < $boundary));
        $validation = array_values(array_filter($all, fn (array $trade) => $trade['opened_at'] >= $boundary));
        $randomValidation = array_values(array_filter($random, fn (array $trade) => $trade['opened_at'] >= $boundary));

        $windows = [];
        $size = max(1, ($end - $start + 60) / 4);

        for ($w = 0; $w < 4; $w++) {
            $from = $start + $w * $size;
            $windows[] = $backtester->summarize(array_values(array_filter($all, fn (array $trade) => $trade['opened_at'] >= $from && $trade['opened_at'] < $from + $size)))['mean_pct'];
        }

        $summary = [
            'period' => ['from' => date(DATE_ATOM, $start), 'to' => date(DATE_ATOM, $end), 'validation_from' => date(DATE_ATOM, $boundary)],
            'pairs' => $pairs,
            'fit' => $backtester->summarize($fit),
            'validation' => $backtester->summarize($validation),
            'random_validation' => $backtester->summarize($randomValidation),
            'windows_mean_pct' => $windows,
            // Compras limitadas que venceram sem executar (entrada a mercado: sempre zero).
            'unfilled' => $unfilled,
        ];

        $failures = $this->failures($summary, (int) $params['min_trades']);

        return $summary + ['failures' => $failures, 'passed' => $failures === []];
    }

    /**
     * What keeps the rule from passing; empty when it passes. All judged on the validation part,
     * which the rule never saw, except the windows, which cover the whole period.
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function failures(array $summary, int $minTrades): array
    {
        $validation = $summary['validation'];
        $failures = [];

        $validation['trades'] >= $minTrades || $failures[] = sprintf('poucas operações na validação (%d; mínimo %d)', $validation['trades'], $minTrades);
        $validation['mean_pct'] > 0 || $failures[] = sprintf('média líquida na validação de %s%% por operação', $this->pct($validation['mean_pct']));
        ($validation['lower95_pct'] ?? -1) > 0 || $failures[] = 'o intervalo de 95% da média na validação inclui zero ou perda';
        $validation['mean_pct'] > $summary['random_validation']['mean_pct'] || $failures[] = sprintf('não ganha da entrada sorteada (%s%%)', $this->pct($summary['random_validation']['mean_pct']));
        count(array_filter($summary['windows_mean_pct'], fn (float $mean) => $mean > 0)) >= 3 || $failures[] = 'positiva em menos de 3 das 4 janelas do período';

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(MicroRule $rule, array $result): void
    {
        $this->newLine();
        $this->info($rule->label());

        $row = fn (string $name, array $s) => [$name, $s['trades'], $s['win_rate'].'%', $s['target'], $s['stop'], $s['time'], $this->pct($s['mean_pct']).'%', $this->pct($s['sum_pct']).'%'];
        $rows = [];

        foreach ($result['pairs'] as $symbol => $summary) {
            $rows[] = $row($symbol, $summary);
        }

        $rows[] = $row('Ajuste', $result['fit']);
        $rows[] = $row('Validação', $result['validation']);
        $rows[] = $row('Sorteio (validação)', $result['random_validation']);

        $this->table(['Par', 'Entradas', 'Acerto', 'Alvo', 'Stop', 'Tempo', 'Média/op', 'Soma'], $rows);
        if (($result['unfilled'] ?? 0) > 0) {
            $this->line("Compras limitadas que venceram sem executar: {$result['unfilled']}.");
        }

        $this->line('Média por janela: '.implode(' / ', array_map(fn (float $mean) => $this->pct($mean).'%', $result['windows_mean_pct'])));

        $result['passed']
            ? $this->info('PASSOU na validação.')
            : $this->warn('NÃO PASSOU: '.implode('; ', $result['failures']).'.');
    }

    /**
     * Candles of the active crypto pairs (or the chosen ones) in the period, oldest first.
     *
     * @return array<string, non-empty-list<array{t: int, open: float, high: float, low: float, close: float, volume: float}>>
     */
    private function candles(int $days): array
    {
        $symbols = array_filter(array_map(fn (string $s) => strtoupper(trim($s)), explode(',', is_string($symbols = $this->option('symbols')) ? $symbols : '')));

        $assets = Asset::query()
            ->where('market', Market::Crypto)
            ->when($symbols !== [], fn ($query) => $query->whereIn('symbol', $symbols), fn ($query) => $query->where('active', true)->where('is_benchmark', false))
            ->orderBy('symbol')
            ->get();

        $series = [];

        foreach ($assets as $asset) {
            $rows = Candle::query()
                ->toBase()
                ->where('asset_id', $asset->id)
                ->where('timeframe', '1m')
                ->where('open_at', '>=', now()->subDays($days))
                ->orderBy('open_at')
                ->get(['open_at', 'open', 'high', 'low', 'close', 'volume']);

            $candles = [];

            foreach ($rows as $row) {
                $candles[] = ['t' => (int) strtotime((string) $row->open_at), 'open' => (float) $row->open, 'high' => (float) $row->high, 'low' => (float) $row->low, 'close' => (float) $row->close, 'volume' => (float) $row->volume];
            }

            if (count($candles) >= 60) {
                $series[$asset->symbol] = $candles;
            }
        }

        return $series;
    }

    private function pct(float $value): string
    {
        return number_format($value, 3, ',', '');
    }
}
