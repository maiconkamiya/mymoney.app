<?php

namespace App\Console\Commands;

use App\Explorer\OpportunityExplorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('explorer:scan {--force : Roda mesmo fora do horário ou com o explorador desligado}')]
#[Description('Varre os pares USDT da Binance e ranqueia as melhores oportunidades')]
class ExploreOpportunities extends Command
{
    public function handle(OpportunityExplorer $explorer): int
    {
        if (! $this->option('force') && (! config('explorer.enabled') || ! self::withinHours((string) config('explorer.hours'), (int) now('UTC')->hour))) {
            $this->line('Explorador desligado ou fora do horário.');

            return self::SUCCESS;
        }

        ['scan' => $scan, 'swarmed' => $swarmed] = $explorer->run();

        if ($scan->error !== null) {
            $this->error($scan->error);

            return self::FAILURE;
        }

        $this->info("{$scan->pairs_seen} pares vistos, {$scan->pairs_passed} passaram nos filtros.");
        $this->table(['#', 'Par', 'Nota', '24 h %', 'Motivo'], $scan->opportunities->map(fn ($o) => [
            $o->rank, $o->symbol, $o->score, $o->change_24h_pct, $o->ai_note ?? implode(' ', $o->reasons),
        ])->all());

        if ($swarmed !== []) {
            $this->info('Enviados aos agentes: '.implode(', ', $swarmed).'.');
        }

        return self::SUCCESS;
    }

    /**
     * "8-22" = das 8h às 22h59 UTC; "22-6" atravessa a meia-noite; vazio = o dia todo.
     */
    public static function withinHours(string $window, int $hour): bool
    {
        if (! preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $window, $match)) {
            return true;
        }

        [$from, $to] = [(int) $match[1], (int) $match[2]];

        return $from <= $to ? $hour >= $from && $hour <= $to : $hour >= $from || $hour <= $to;
    }
}
