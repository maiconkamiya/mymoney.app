<?php

namespace App\News;

use App\Agents\Llm\LlmClient;
use App\Models\Asset;
use App\Models\NewsItem;

/**
 * Two-step classification: a cheap model tags assets, sentiment and relevance
 * for every item; only relevant items get a second look from the strong model.
 */
class NewsTriage
{
    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @return list<Asset> Assets the item was attached to.
     */
    public function triage(NewsItem $news): array
    {
        $symbols = Asset::query()->where('active', true)->where('is_benchmark', false)->pluck('symbol')->all();

        $response = $this->llm->json(
            config('agents.models.fast'),
            'Você classifica notícias financeiras para um sistema de trading. Liste apenas os ativos da lista fornecida que a notícia afeta diretamente. '
                .'sentiment vai de -1 (muito negativo) a 1 (muito positivo); relevance de 0 a 1 mede o impacto provável no preço. '
                .'is_new_fact é false quando a notícia repete algo já conhecido.',
            'Ativos acompanhados: '.implode(', ', $symbols)."\n\nTítulo: {$news->title}\n\n{$news->body}",
            self::triageSchema(),
        );

        $attached = [];
        $threshold = config('news.deep_analysis_threshold');

        foreach ($response->data['assets'] ?? [] as $row) {
            $asset = Asset::query()->where('symbol', strtoupper($row['symbol'] ?? ''))->first();

            if ($asset === null) {
                continue;
            }

            $relevance = max(0, min(1, (float) $row['relevance']));
            $analysis = $relevance >= $threshold ? $this->analyze($news, $asset) : null;

            $news->assets()->syncWithoutDetaching([$asset->id => [
                'sentiment' => max(-1, min(1, (float) $row['sentiment'])),
                'relevance' => $relevance,
                'is_new_fact' => (bool) $row['is_new_fact'],
                'analysis' => $analysis === null ? null : json_encode($analysis, JSON_UNESCAPED_UNICODE),
            ]]);

            $attached[] = $asset;
        }

        $news->update(['triaged_at' => now()]);

        return $attached;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(NewsItem $news, Asset $asset): array
    {
        return $this->llm->json(
            config('agents.models.strong'),
            'Você é um analista sênior. Avalie o impacto esperado desta notícia no ativo, o horizonte em que ele deve aparecer e se o preço provavelmente já reagiu.',
            "Ativo: {$asset->symbol}\nPublicada em: {$news->published_at}\nTítulo: {$news->title}\n\n{$news->body}",
            [
                'type' => 'object',
                'properties' => [
                    'impact' => ['type' => 'string', 'enum' => ['positive', 'negative', 'neutral']],
                    'horizon' => ['type' => 'string', 'enum' => ['intraday', 'days', 'weeks']],
                    'already_priced' => ['type' => 'boolean'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['impact', 'horizon', 'already_priced', 'summary'],
                'additionalProperties' => false,
            ],
        )->data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function triageSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string'],
                'assets' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'symbol' => ['type' => 'string'],
                            'sentiment' => ['type' => 'number'],
                            'relevance' => ['type' => 'number'],
                            'is_new_fact' => ['type' => 'boolean'],
                        ],
                        'required' => ['symbol', 'sentiment', 'relevance', 'is_new_fact'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['category', 'assets'],
            'additionalProperties' => false,
        ];
    }
}
