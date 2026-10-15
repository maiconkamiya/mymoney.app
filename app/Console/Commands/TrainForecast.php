<?php

namespace App\Console\Commands;

use App\Models\Candle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use SplFileObject;

#[Signature('forecast:train {--horizon= : Velas à frente (padrão: FORECAST_HORIZON)} {--days= : Dias de histórico (padrão: FORECAST_TRAINING_DAYS)} {--fee-bps=20 : Custo de ida e volta no backtest, em pontos-base}')]
#[Description('Exporta candles e notícias, treina o modelo de previsão em Python e roda o backtest')]
class TrainForecast extends Command
{
    public function handle(): int
    {
        $horizon = (int) ($this->option('horizon') ?: config('forecast.horizon'));
        $days = (int) ($this->option('days') ?: config('forecast.training_days'));
        $since = now()->subDays($days);
        $disk = Storage::disk('local');

        $candles = $this->exportCandles($disk->path('forecast/candles.csv'), $since);
        $this->exportNews($disk->path('forecast/news.csv'), $since);
        $this->info("{$candles} candles exportados (últimos {$days} dias).");

        $feeBps = $this->option('fee-bps');

        $result = Process::path(base_path('services/forecast'))
            ->timeout(1800)
            ->env(['FORECAST_MODEL_DIR' => $disk->path('forecast/models')])
            ->run([
                (string) config('forecast.python'), '-m', 'forecast.train',
                '--candles', $disk->path('forecast/candles.csv'),
                '--news', $disk->path('forecast/news.csv'),
                '--horizon', (string) $horizon,
                '--fee-bps', is_numeric($feeBps) ? (string) $feeBps : '20',
            ]);

        $this->line(trim($result->output()));

        if ($result->failed()) {
            $this->error(trim($result->errorOutput()) ?: 'O treino em Python falhou.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function exportCandles(string $path, CarbonInterface $since): int
    {
        $this->prepare($path);
        $file = new SplFileObject($path, 'w');
        $file->fputcsv(['symbol', 'timeframe', 'open_at', 'open', 'high', 'low', 'close', 'volume']);

        $count = 0;
        Candle::query()
            ->with('asset:id,symbol')
            ->where('timeframe', '1m')
            ->where('open_at', '>=', $since)
            ->where('open_at', '<=', now())
            ->orderBy('open_at')
            ->lazy(5000)
            ->each(function (Candle $c) use ($file, &$count) {
                $file->fputcsv([$c->asset->symbol, $c->timeframe, $c->open_at->toIso8601String(), $c->open, $c->high, $c->low, $c->close, $c->volume]);
                $count++;
            });

        return $count;
    }

    /**
     * News enter with the moment the system learned of them (fetched_at), the
     * same filter the live scenario uses, so training never sees the future.
     */
    private function exportNews(string $path, CarbonInterface $since): void
    {
        $file = new SplFileObject($path, 'w');
        $file->fputcsv(['symbol', 'fetched_at', 'sentiment', 'relevance']);

        DB::table('news_asset')
            ->join('news_items', 'news_items.id', '=', 'news_asset.news_item_id')
            ->join('assets', 'assets.id', '=', 'news_asset.asset_id')
            ->where('news_items.fetched_at', '>=', $since->copy()->subDays(2))
            ->orderBy('news_items.fetched_at')
            ->select('assets.symbol', 'news_items.fetched_at', 'news_asset.sentiment', 'news_asset.relevance')
            ->lazy(2000)
            ->each(function (object $row) use ($file) {
                $file->fputcsv([$row->symbol, Carbon::parse($row->fetched_at)->toIso8601String(), $row->sentiment, $row->relevance]);
            });

    }

    private function prepare(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
    }
}
