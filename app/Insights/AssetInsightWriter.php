<?php

namespace App\Insights;

use App\Agents\Llm\LlmClient;
use App\Agents\LlmAgent;
use App\Models\Asset;
use App\Models\AssetInsight;
use App\Models\NewsItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Por que X está em alta/queda hoje?": uma chamada a um modelo barato com os
 * números que o código já calculou (variações, faixa do dia, sentimento) e as
 * manchetes coletadas. A direção vem do código, não do modelo.
 */
class AssetInsightWriter
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PriceHistory $history,
        private readonly FearGreedIndex $sentiment,
    ) {}

    /**
     * A análise nova, ou null com a chave desligada, sem histórico ou se o modelo falhou.
     */
    public function write(Asset $asset): ?AssetInsight
    {
        if (! config('insights.ai.enabled')) {
            return null;
        }

        $history = $this->history->for($asset->symbol);

        if ($history === null || ! isset($history['24h'])) {
            return null;
        }

        $day = $history['24h'];
        $direction = match (true) {
            $day['change_pct'] >= 0.5 => 'up',
            $day['change_pct'] <= -0.5 => 'down',
            default => 'flat',
        };
        $news = $this->news($asset);
        $sentiment = $this->sentiment->current();

        $data = [
            'symbol' => $asset->symbol,
            'name' => $asset->name,
            'direction_24h' => $direction,
            'change_pct' => array_map(fn (array $p) => $p['change_pct'], $history),
            'price_now' => $day['close'],
            'high_24h' => $day['high'],
            'low_24h' => $day['low'],
            // Fechamentos de hora em hora nas últimas 24 h, para ver quando o movimento aconteceu.
            'hourly_closes_24h' => array_values(array_map(fn (array $point) => $point[1], array_filter($day['points'], fn (int $i) => $i % 4 === 3, ARRAY_FILTER_USE_KEY))),
            'market_fear_greed' => $sentiment === null ? null : ['value' => $sentiment['value'], 'label' => $sentiment['label'], 'yesterday' => $sentiment['yesterday']],
            'headlines' => $news,
        ];

        $verb = ['up' => 'em alta', 'down' => 'em queda', 'flat' => 'de lado'][$direction];

        try {
            $response = $this->llm->json(
                (string) config('insights.ai.model'),
                'Você explica para um investidor pessoa física por que um criptoativo está '.$verb.' hoje. '
                    .'Use só os números e as manchetes recebidos; não invente notícias, datas nem valores. '
                    .'Se as manchetes não explicam o movimento, diga isso e explique pelo que os números mostram (horário do movimento, faixa do dia, comparação com 7 dias e com o sentimento do mercado). '
                    .'Não recomende comprar nem vender. Escreva em português do Brasil, frases curtas, sem travessões. '
                    .'summary: duas ou três frases. points: de dois a quatro tópicos curtos com os fatores.',
                "Dados do ativo:\n".json_encode($data, LlmAgent::jsonFlags()),
                self::schema(),
            );
        } catch (Throwable $e) {
            Log::warning("Análise do dia de {$asset->symbol} falhou: {$e->getMessage()}");

            return null;
        }

        $summary = trim((string) ($response->data['summary'] ?? ''));

        if ($summary === '') {
            return null;
        }

        $points = [];

        foreach ((array) ($response->data['points'] ?? []) as $point) {
            $text = is_string($point) ? trim($point) : '';

            if ($text !== '' && count($points) < 4) {
                $points[] = mb_substr($text, 0, 300);
            }
        }

        return AssetInsight::query()->create([
            'asset_id' => $asset->id,
            'direction' => $direction,
            'change_24h_pct' => $day['change_pct'],
            'summary' => mb_substr($summary, 0, 1200),
            'points' => $points,
            'news_count' => count($news),
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $response->cost(),
        ]);
    }

    /**
     * Até 8 manchetes das últimas 48 h: primeiro as ligadas ao ativo, depois as gerais de cripto.
     *
     * @return list<array{title: string, source: string, published_at: string|null}>
     */
    private function news(Asset $asset): array
    {
        $since = now()->subHours(48);
        $cryptoSources = collect((array) config('news.sources'))->filter(fn (mixed $s) => is_array($s) && ($s['market'] ?? null) === 'crypto')->keys()->all();

        $own = $asset->news()->where('news_items.fetched_at', '>=', $since)->whereNull('duplicate_of_id')->latest('news_items.fetched_at')->limit(5)->get();
        $general = NewsItem::query()
            ->whereIn('source', $cryptoSources)
            ->where('fetched_at', '>=', $since)
            ->whereNull('duplicate_of_id')
            ->whereNotIn('id', $own->pluck('id'))
            ->latest('fetched_at')
            ->limit(8 - $own->count())
            ->get();

        return array_values($own->concat($general)->map(fn (NewsItem $item) => [
            'title' => $item->title,
            'source' => $item->source,
            'published_at' => $item->published_at?->toIso8601String(),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'points' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['summary', 'points'],
            'additionalProperties' => false,
        ];
    }
}
