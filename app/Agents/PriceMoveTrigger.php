<?php

namespace App\Agents;

use App\Models\Candle;
use App\Models\Signal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calls the agents right away when the price moves sharply, without waiting
 * for the scheduled scanner. It listens to every 1-minute candle written, no
 * matter who wrote it (REST sync or the stream collector), and compares the
 * close with the close `window_minutes` ago.
 */
class PriceMoveTrigger
{
    /** Seconds between two checks of the same asset: the collector writes every second. */
    private const CHECK_EVERY_SECONDS = 5;

    public function __construct(private readonly SignalScanner $scanner) {}

    /**
     * @return list<Signal>
     */
    public function handle(Candle $candle): array
    {
        $config = config('trading.move_trigger');

        if (! $config['enabled'] || $config['pct'] <= 0 || $candle->timeframe !== '1m') {
            return [];
        }

        // Backfills and old klines are not news.
        if ($candle->open_at->lt(now()->subMinutes(2)) || ! Cache::add("move-check:{$candle->asset_id}", 1, self::CHECK_EVERY_SECONDS)) {
            return [];
        }

        if (Cache::has("move-cooldown:{$candle->asset_id}")) {
            return [];
        }

        $window = $config['window_minutes'];

        $reference = Candle::query()
            ->where('asset_id', $candle->asset_id)
            ->where('timeframe', '1m')
            ->where('open_at', '<=', $candle->open_at->subMinutes($window))
            ->where('open_at', '>=', $candle->open_at->subMinutes($window + 2))
            ->latest('open_at')
            ->first();

        if ($reference === null || (float) $reference->close <= 0) {
            return [];
        }

        $change = ((float) $candle->close - (float) $reference->close) / (float) $reference->close * 100;

        if (abs($change) < $config['pct']) {
            return [];
        }

        $asset = $candle->asset;
        $reason = sprintf('Gatilho de preço: %s%s%% em %d min.', $change > 0 ? '+' : '', number_format($change, 2, ',', ''), $window);

        try {
            $signals = $this->scanner->scan($asset, $reason);
        } catch (Throwable $e) {
            // A failed scan must never break the market data writer.
            Log::warning("Gatilho de preço de {$asset->symbol} falhou: {$e->getMessage()}");

            return [];
        }

        if ($signals !== [] && $config['cooldown_minutes'] > 0) {
            Cache::put("move-cooldown:{$candle->asset_id}", true, now()->addMinutes($config['cooldown_minutes']));
        }

        return $signals;
    }
}
