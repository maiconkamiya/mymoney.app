<?php

namespace App\Insights;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sentimento do mercado cripto pelo índice Fear & Greed da alternative.me (0 a 100,
 * gratuito, sem chave). Vale para o mercado inteiro e muda uma vez por dia.
 */
class FearGreedIndex
{
    private const LABELS = [
        'Extreme Fear' => 'Medo extremo',
        'Fear' => 'Medo',
        'Neutral' => 'Neutro',
        'Greed' => 'Ganância',
        'Extreme Greed' => 'Ganância extrema',
    ];

    /**
     * @return array{value: int, label: string, updated_at: string, yesterday: int|null, last_week: int|null, last_month: int|null, history: list<array{0: int, 1: int}>}|null
     *                                                                                                                                                                         null quando a fonte não respondeu
     */
    public function current(): ?array
    {
        try {
            return Cache::remember('fear-greed', now()->addMinutes(max(1, (int) config('insights.fear_greed.cache_minutes'))), fn () => $this->fetch());
        } catch (Throwable $e) {
            Log::warning("Índice Fear & Greed falhou: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @return array{value: int, label: string, updated_at: string, yesterday: int|null, last_week: int|null, last_month: int|null, history: list<array{0: int, 1: int}>}
     */
    private function fetch(): array
    {
        $rows = Http::timeout(10)
            ->get((string) config('insights.fear_greed.url'), ['limit' => 31, 'format' => 'json'])
            ->throw()
            ->json('data');

        $days = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['value'], $row['timestamp'])) {
                $days[] = ['value' => (int) $row['value'], 'label' => (string) ($row['value_classification'] ?? ''), 'at' => (int) $row['timestamp']];
            }
        }

        if ($days === []) {
            throw new RuntimeException('resposta sem dados');
        }

        // A fonte manda do mais novo para o mais antigo; ordena para não depender disso.
        usort($days, fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return [
            'value' => $days[0]['value'],
            'label' => self::label($days[0]['label'], $days[0]['value']),
            'updated_at' => Carbon::createFromTimestamp($days[0]['at'])->toIso8601String(),
            'yesterday' => $days[1]['value'] ?? null,
            'last_week' => $days[7]['value'] ?? null,
            'last_month' => $days[30]['value'] ?? null,
            'history' => array_reverse(array_map(fn (array $day) => [$day['at'] * 1000, $day['value']], $days)),
        ];
    }

    public static function label(string $classification, int $value): string
    {
        return self::LABELS[$classification] ?? match (true) {
            $value <= 24 => 'Medo extremo',
            $value <= 46 => 'Medo',
            $value <= 54 => 'Neutro',
            $value <= 75 => 'Ganância',
            default => 'Ganância extrema',
        };
    }
}
