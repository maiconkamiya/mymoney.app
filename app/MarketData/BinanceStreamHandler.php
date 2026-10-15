<?php

namespace App\MarketData;

use App\Events\CandleUpdated;
use App\Models\Asset;
use App\Models\Candle;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns one Binance combined-stream message into a stored candle, a collector
 * heartbeat and a broadcast. Knows nothing about sockets, so it is testable
 * with plain JSON.
 */
#[Singleton]
class BinanceStreamHandler
{
    /** @var array<string, Asset> */
    private array $assets = [];

    public function __construct(
        private readonly CandleAggregator $aggregator,
        private readonly CollectorStatus $status,
    ) {}

    /**
     * @param  Collection<int, Asset>  $assets
     */
    public function track(Collection $assets): void
    {
        $this->assets = $assets->keyBy(fn (Asset $asset) => strtoupper($asset->symbol))->all();
    }

    /**
     * @return list<string>
     */
    public function streams(): array
    {
        return array_map(fn (string $symbol) => strtolower($symbol).'@kline_1m', array_keys($this->assets));
    }

    public function handle(string $payload): ?Candle
    {
        $message = json_decode($payload, true);
        $data = is_array($message) ? ($message['data'] ?? $message) : null;
        $kline = is_array($data) ? ($data['k'] ?? null) : null;

        if (! is_array($kline) || ($data['e'] ?? null) !== 'kline') {
            return null;
        }

        $asset = $this->assets[strtoupper((string) ($kline['s'] ?? ''))] ?? null;

        if ($asset === null || ($kline['i'] ?? null) !== '1m') {
            return null;
        }

        $candle = $this->aggregator->upsertKline($asset, [
            'open_at' => Carbon::createFromTimestampMs((int) $kline['t']),
            'open' => (float) $kline['o'],
            'high' => (float) $kline['h'],
            'low' => (float) $kline['l'],
            'close' => (float) $kline['c'],
            'volume' => (float) $kline['v'],
        ]);

        $eventAt = Carbon::createFromTimestampMs((int) ($data['E'] ?? now()->getTimestampMs()));

        $this->status->recordTick($asset->symbol, (float) $kline['c'], $eventAt);

        CandleUpdated::dispatch(
            $asset->symbol,
            '1m',
            [
                'time' => $candle->open_at->getTimestamp(),
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
                'volume' => (float) $candle->volume,
            ],
            (bool) ($kline['x'] ?? false),
            $eventAt->getTimestamp(),
        );

        return $candle;
    }
}
