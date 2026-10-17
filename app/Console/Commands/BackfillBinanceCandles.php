<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\MarketData\BinanceKlines;
use App\Models\Asset;
use App\Models\Candle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:backfill-binance {--days=30 : Dias de histórico a baixar} {--symbol= : Só este par (padrão: todos os ativos cripto ativos)}')]
#[Description('Baixa candles de 1 minuto antigos da Binance para os ativos cripto, para treinar o modelo de previsão')]
class BackfillBinanceCandles extends Command
{
    public function handle(BinanceKlines $binance): int
    {
        $days = max(1, (int) $this->option('days'));
        $symbol = $this->option('symbol');
        $symbol = is_string($symbol) && $symbol !== '' ? strtoupper($symbol) : null;

        $assets = Asset::query()
            ->where('market', Market::Crypto)
            ->where('active', true)
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->get();

        if ($assets->isEmpty()) {
            $this->error('Nenhum ativo cripto ativo encontrado.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($assets as $asset) {
            try {
                $total = $this->backfill($binance, $asset, $days);
                $this->info("{$asset->symbol}: {$total} candles gravados.");
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$asset->symbol}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function backfill(BinanceKlines $binance, Asset $asset, int $days): int
    {
        $from = now()->subDays($days)->startOfMinute();
        $until = now()->startOfMinute();
        $total = 0;

        while ($from->lt($until)) {
            $klines = $binance->since($asset->symbol, $from);

            if ($klines === []) {
                break;
            }

            Candle::upsert(
                array_map(fn (array $kline) => [
                    'asset_id' => $asset->id,
                    'timeframe' => '1m',
                    'open_at' => $kline['open_at'],
                    'open' => $kline['open'],
                    'high' => $kline['high'],
                    'low' => $kline['low'],
                    'close' => $kline['close'],
                    'volume' => $kline['volume'],
                ], $klines),
                ['asset_id', 'timeframe', 'open_at'],
                ['open', 'high', 'low', 'close', 'volume'],
            );

            $total += count($klines);
            $next = end($klines)['open_at']->copy()->addMinute();

            if ($next->lte($from)) {
                break;
            }

            $from = $next;
        }

        return $total;
    }
}
