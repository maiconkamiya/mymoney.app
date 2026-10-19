<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Events\PortfolioChanged;
use App\Models\Portfolio;
use App\Trading\Micro\MicroTrader;
use App\Trading\PortfolioChangeBroadcaster;
use App\Trading\PortfolioLock;
use App\Trading\PositionExits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('micro:watch
    {--max-seconds=0 : Encerra depois desse tempo (0 = roda para sempre)}
    {--once : Faz uma passada só e sai}')]
#[Description('Motor das micro operações em tempo real: stop e alvo a cada segundo, entradas a cada poucos segundos')]
class WatchMicroOperations extends Command
{
    /** @var array<int, float> position id => when it was last sent to the exits check */
    private array $triggered = [];

    /** @var array<int, float> portfolio id => when the whole engine last ran */
    private array $ran = [];

    /** @var array<int, float> portfolio id => when the screen was last told */
    private array $notified = [];

    public function handle(MicroTrader $trader, PositionExits $exits): int
    {
        $deadline = (int) $this->option('max-seconds') > 0 ? time() + (int) $this->option('max-seconds') : null;
        $tick = (int) config('trading.micro.realtime.exit_seconds');

        $this->info('Motor das micro operações em tempo real ligado.');

        do {
            $started = microtime(true);
            MicroTrader::markRealtime();

            foreach ($this->portfolios() as $portfolio) {
                try {
                    // Busy with the minute run, the exits job or the queue: the next pass tries again.
                    PortfolioLock::run($portfolio->id, fn () => $this->pass($portfolio, $trader, $exits));
                } catch (Throwable $e) {
                    // Um aviso por minuto por carteira, não um por segundo.
                    if (Cache::add('micro-watch:error:'.$portfolio->id, true, 60)) {
                        Log::error('Motor em tempo real falhou na carteira', ['portfolio' => $portfolio->id, 'error' => $e->getMessage()]);
                        $this->error("Carteira #{$portfolio->id}: {$e->getMessage()}");
                    }
                }
            }

            if ($this->option('once')) {
                break;
            }

            $rest = $tick - (microtime(true) - $started);

            if ($rest > 0) {
                usleep((int) ($rest * 1_000_000));
            }
        } while ($deadline === null || time() < $deadline);

        return self::SUCCESS;
    }

    /**
     * One look at the portfolio, holding its lock: books the limit buys that
     * executed (or cancels the expired ones), sells the cycles whose
     * price crossed the stop or the target, then runs the whole engine
     * (books, time limit, resting sales, entries) when it is due or when a
     * cycle just closed and freed room for a new one.
     */
    private function pass(Portfolio $portfolio, MicroTrader $trader, PositionExits $exits): void
    {
        $now = microtime(true);
        $closed = 0;

        // A limit buy that executed starts its cycle within the second; an expired one is cancelled.
        $started = $trader->pendingBuys($portfolio);

        if ($portfolio->micro_enabled && $portfolio->isRunning()) {
            // A sale that did not go out (refused, under the minimum) waits a few seconds, not one.
            $retry = (int) config('trading.micro.realtime.retry_seconds');
            $due = array_values(array_filter($trader->due($portfolio), fn (int $id) => $now - ($this->triggered[$id] ?? 0.0) >= $retry));

            if ($due !== []) {
                foreach ($due as $id) {
                    $this->triggered[$id] = $now;
                }

                $closed = $exits->checkPortfolio($portfolio, $due);
            }

            $this->triggered = array_filter($this->triggered, fn (float $at) => $now - $at < 60);
        }

        $opened = $started;

        if ($closed > 0 || $now - ($this->ran[$portfolio->id] ?? 0.0) >= (int) config('trading.micro.realtime.entry_seconds')) {
            $this->ran[$portfolio->id] = $now;
            $opened += $trader->run($portfolio);
        }

        if ($closed + $opened > 0) {
            $this->line(sprintf('%s carteira #%d: %d ciclo(s) encerrado(s), %d aberto(s).', now()->format('H:i:s'), $portfolio->id, $closed, $opened));
        }

        // Ordens e ciclos já avisam a tela sozinhos; o motivo por par e a hora da avaliação vivem no cache.
        if ($closed + $opened > 0 || $now - ($this->notified[$portfolio->id] ?? 0.0) >= (int) config('trading.micro.realtime.notify_seconds')) {
            $this->notified[$portfolio->id] = $now;
            PortfolioChangeBroadcaster::notify($portfolio->id, [PortfolioChanged::SCOPE_MICRO]);
        }
    }

    /**
     * @return Collection<int, Portfolio>
     */
    private function portfolios(): Collection
    {
        return Portfolio::query()
            ->where('market', Market::Crypto)
            // A limit buy left waiting when the mode was switched off still has to be cancelled.
            ->where(fn ($query) => $query->where('micro_enabled', true)->orWhereNotNull('micro_liquidate_asset_ids')->orWhereHas('microCycles', fn ($cycles) => $cycles->where('status', 'pending')))
            ->get();
    }
}
