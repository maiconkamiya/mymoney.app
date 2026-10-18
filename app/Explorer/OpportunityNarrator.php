<?php

namespace App\Explorer;

use App\Agents\Llm\LlmClient;
use App\Agents\Llm\LlmResponse;
use App\Agents\LlmAgent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uma única chamada a um modelo barato por varredura: explica cada par em uma
 * linha e devolve a ordem que ele recomenda. Só reordena o que o código já
 * escolheu; sem resposta válida, fica a ordem da nota.
 */
class OpportunityNarrator
{
    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @param  list<array<string, mixed>>  $rows  pares já ranqueados, com symbol, score e os números
     * @return array{order: list<string>, notes: array<string, string>, response: LlmResponse}|null
     */
    public function narrate(array $rows): ?array
    {
        if ($rows === [] || ! config('explorer.ai.enabled')) {
            return null;
        }

        $table = array_map(fn (array $row) => [
            'symbol' => $row['symbol'],
            'score' => $row['score'],
            'change_24h_pct' => $row['change_pct'],
            'quote_volume_usdt' => round($row['quote_volume']),
            'spread_pct' => $row['spread_pct'],
            'volume_accel_1h' => $row['volume_accel'],
            'breakout_24h_high' => $row['breakout'],
            'extension_atr_above_ema21_1h' => $row['extension_atr'],
        ], $rows);

        try {
            $response = $this->llm->json(
                (string) config('explorer.ai.model'),
                'Você é um analista de cripto que filtra oportunidades de compra de curto prazo para um sistema que opera com ordens pequenas (custo de ida e volta ~0,30%). '
                    .'Os números foram calculados por código; não invente outros. Prefira volume acelerando, rompimento recente e preço ainda perto da média; '
                    .'desconfie de alta sem volume e de preço já esticado. Em items, devolva cada símbolo uma vez, do melhor para o pior, '
                    .'com note: uma frase curta em português explicando o porquê, sem travessões.',
                "Pares ranqueados pela nota do código:\n".json_encode($table, LlmAgent::jsonFlags()),
                self::schema(),
            );
        } catch (Throwable $e) {
            Log::warning("Explicação do explorador falhou: {$e->getMessage()}");

            return null;
        }

        $known = array_column($rows, 'symbol');
        $order = [];
        $notes = [];

        foreach ((array) ($response->data['items'] ?? []) as $item) {
            $symbol = is_array($item) ? strtoupper((string) ($item['symbol'] ?? '')) : '';

            if (! in_array($symbol, $known, true) || in_array($symbol, $order, true)) {
                continue;
            }

            $order[] = $symbol;
            $note = trim((string) ($item['note'] ?? ''));

            if ($note !== '') {
                $notes[$symbol] = mb_substr($note, 0, 300);
            }
        }

        return ['order' => $order, 'notes' => $notes, 'response' => $response];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'symbol' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['symbol', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }
}
