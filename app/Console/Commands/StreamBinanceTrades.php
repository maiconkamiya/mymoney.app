<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Events\CotacaoAtualizada;
use App\MarketData\CandleAggregator;
use App\MarketData\TradeStream;
use App\Models\Asset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('market:stream-binance {--max-seconds=0 : Encerra depois desse tempo (0 = roda para sempre)}')]
#[Description('Escuta os negócios da Binance por WebSocket, grava os candles de 1 minuto e transmite as cotações')]
class StreamBinanceTrades extends Command
{
    /** Segundos de espera antes de reconectar, crescendo até o teto. */
    private const BACKOFF = [1, 2, 5, 10, 30];

    public function handle(TradeStream $stream, CandleAggregator $aggregator): int
    {
        $assets = $this->activeAssets();

        if ($assets->isEmpty()) {
            $this->warn('Nenhum ativo cripto ativo para acompanhar.');

            return self::SUCCESS;
        }

        $reloadEvery = max(1, (int) config('trading.binance.stream_reload_seconds'));

        $deadline = (int) $this->option('max-seconds') > 0 ? time() + (int) $this->option('max-seconds') : null;
        $failures = 0;

        while ($deadline === null || time() < $deadline) {
            $assets = $this->activeAssets();

            if ($assets->isEmpty()) {
                $this->warn('Nenhum ativo cripto ativo para acompanhar; conferindo de novo em instantes.');
                sleep($reloadEvery);

                continue;
            }

            $subscribed = $assets->keys()->sort()->values()->all();
            $reload = false;
            $nextCheck = time() + $reloadEvery;

            /** @var array<string, array{price: float, volume: float}> $pending */
            $pending = [];

            $onTrade = function (string $symbol, float $price, float $volume, int $ms) use ($assets, &$pending): void {
                if (! $assets->has($symbol)) {
                    return;
                }

                $pending[$symbol] = [
                    'price' => $price,
                    'volume' => ($pending[$symbol]['volume'] ?? 0.0) + $volume,
                    'at' => $ms,
                ];
            };

            // Uma gravação e uma transmissão por ativo por segundo, não por negócio.
            $onTick = function () use (&$pending, $assets, $aggregator, $deadline, &$failures, &$reload, &$nextCheck, $reloadEvery, $subscribed): bool {
                foreach ($pending as $symbol => $trade) {
                    $candle = $aggregator->ingestTrade($assets[$symbol], $trade['price'], $trade['volume'], Carbon::createFromTimestampMs($trade['at']));
                    $failures = 0;

                    CotacaoAtualizada::dispatch($symbol, $trade['price'], [
                        'open_at' => $candle->open_at->toIso8601String(),
                        'open' => (float) $candle->open,
                        'high' => (float) $candle->high,
                        'low' => (float) $candle->low,
                        'close' => (float) $candle->close,
                        'volume' => (float) $candle->volume,
                    ]);
                }

                $pending = [];

                // Ativo cadastrado ou removido pela tela: reconecta com a lista nova, sem reiniciar o serviço.
                if (time() >= $nextCheck) {
                    $nextCheck = time() + $reloadEvery;

                    if ($this->activeAssets()->keys()->sort()->values()->all() !== $subscribed) {
                        $reload = true;

                        return false;
                    }
                }

                return $deadline === null || time() < $deadline;
            };

            try {
                $stream->listen(array_values(array_map('strval', $assets->keys()->all())), $onTrade, $onTick);

                if ($deadline !== null && time() >= $deadline) {
                    break;
                }

                if ($reload) {
                    $this->line('Lista de pares mudou: reconectando com os ativos novos.');
                    $failures = 0;

                    continue;
                }
            } catch (Throwable $e) {
                $this->error("Conexão com a Binance caiu: {$e->getMessage()}");
            }

            $wait = self::BACKOFF[min($failures, count(self::BACKOFF) - 1)];
            $failures++;
            $this->line("Reconectando em {$wait}s.");
            sleep($wait);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<string, Asset>
     */
    private function activeAssets(): Collection
    {
        return Asset::query()->where('market', Market::Crypto)->where('active', true)->get()->keyBy('symbol');
    }
}
