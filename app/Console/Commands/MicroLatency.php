<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('micro:latency
    {--hours=24 : Janela, em horas, das compras medidas}
    {--portfolio= : Só esta carteira}
    {--rows=15 : Quantas compras listar, das mais recentes}')]
#[Description('Mede o tempo entre a leitura do gráfico e a resposta da Binance nas compras micro, e quanto o preço andou nesse meio')]
class MicroLatency extends Command
{
    public function handle(): int
    {
        $portfolio = $this->option('portfolio') !== null ? (int) $this->option('portfolio') : null;

        /** @var Collection<int, AuditLog> $logs */
        $logs = AuditLog::query()
            ->where('action', 'micro.buy_latency')
            ->where('created_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))
            ->orderBy('id')
            ->get()
            ->filter(fn (AuditLog $log) => $portfolio === null || (int) ($log->payload['portfolio_id'] ?? 0) === $portfolio)
            ->values();

        if ($logs->isEmpty()) {
            $this->warn('Nenhuma compra micro medida nessa janela (a medição existe desde a versão 0.3.89).');

            return self::SUCCESS;
        }

        $orders = Order::query()->whereIn('id', $logs->pluck('subject_id')->filter())->get()->keyBy('id');

        $rows = $logs->map(function (AuditLog $log) use ($orders): array {
            $p = $log->payload ?? [];
            $read = (float) ($p['price_read'] ?? 0);
            $answered = isset($p['price_answered']) ? (float) $p['price_answered'] : null;
            $order = $orders->get((int) $log->subject_id);

            return [
                'at' => $log->created_at->format('d/m H:i:s'),
                'portfolio' => (int) ($p['portfolio_id'] ?? 0),
                'symbol' => (string) ($p['symbol'] ?? '?'),
                'mode' => (string) ($p['mode'] ?? '?'),
                'decision_ms' => (int) ($p['decision_ms'] ?? 0),
                'send_ms' => (int) ($p['send_ms'] ?? 0),
                'move_pct' => $read > 0 && $answered !== null ? ($answered - $read) / $read * 100 : null,
                'limit' => isset($p['limit_price']) ? (float) $p['limit_price'] : null,
                'read' => $read,
                'filled' => $order?->status === OrderStatus::Filled || (float) ($order?->fills()->sum('qty') ?? 0) > 0,
                'status' => $order?->status->value ?? (string) ($p['status'] ?? '?'),
            ];
        });

        $moves = $rows->pluck('move_pct')->filter(fn ($move) => $move !== null)->values();
        $limits = $rows->where('mode', 'limit');

        $this->info(sprintf('%d compra(s) micro medidas nas últimas %d h.', $rows->count(), max(1, (int) $this->option('hours'))));
        $this->line(sprintf('Leitura do gráfico até o envio: mediana %d ms, 90%% abaixo de %d ms, maior %d ms.', $this->percentile($rows->pluck('decision_ms'), 50), $this->percentile($rows->pluck('decision_ms'), 90), (int) $rows->max('decision_ms')));
        $this->line(sprintf('Envio até a resposta da Binance: mediana %d ms, 90%% abaixo de %d ms, maior %d ms.', $this->percentile($rows->pluck('send_ms'), 50), $this->percentile($rows->pluck('send_ms'), 90), (int) $rows->max('send_ms')));

        if ($moves->isNotEmpty()) {
            $this->line(sprintf('Preço da leitura até a resposta: mediana %+.3f%%; subiu em %d de %d compras.', $this->median($moves), $moves->filter(fn (float $move) => $move > 0)->count(), $moves->count()));
        }

        if ($limits->isNotEmpty()) {
            $this->line(sprintf('Compras limitadas executadas (total ou parcial): %d de %d.', $limits->where('filled', true)->count(), $limits->count()));
        }

        $this->table(
            ['Quando', 'Carteira', 'Par', 'Tipo', 'Decisão (ms)', 'Binance (ms)', 'Preço lido', 'Limite', 'Andou', 'Situação'],
            $rows->reverse()->take(max(1, (int) $this->option('rows')))->map(fn (array $row) => [
                $row['at'],
                '#'.$row['portfolio'],
                $row['symbol'],
                $row['mode'] === 'limit' ? 'limitada' : 'a mercado',
                $row['decision_ms'],
                $row['send_ms'],
                round($row['read'], 8),
                $row['limit'] !== null ? round($row['limit'], 8) : '',
                $row['move_pct'] !== null ? sprintf('%+.3f%%', $row['move_pct']) : '',
                $row['filled'] ? 'executou' : $row['status'],
            ])->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function percentile(Collection $values, int $pct): int
    {
        $sorted = $values->map(fn ($value) => (int) $value)->sort()->values();

        return (int) $sorted->get((int) max(0, ceil($pct / 100 * $sorted->count()) - 1), 0);
    }

    /**
     * @param  Collection<int, float>  $values
     */
    private function median(Collection $values): float
    {
        return (float) $values->median();
    }
}
