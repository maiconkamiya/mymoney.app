<?php

namespace App\News\Embeddings;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls any embeddings endpoint that speaks the OpenAI format
 * (POST {input, model} -> data[0].embedding), such as Voyage AI or OpenAI.
 */
class HttpEmbedder implements Embedder
{
    public function __construct(
        private readonly string $url,
        private readonly string $key,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function embed(string $text): array
    {
        if ($this->key === '') {
            throw new EmbeddingException('NEWS_EMBEDDINGS_KEY não configurada.');
        }

        try {
            $response = Http::withToken($this->key)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->url, ['input' => [$text], 'model' => $this->model])
                ->throw();
        } catch (Throwable $e) {
            throw new EmbeddingException('Falha ao gerar embedding: '.$e->getMessage(), 0, $e);
        }

        $vector = $response->json('data.0.embedding');

        if (! is_array($vector) || $vector === []) {
            throw new EmbeddingException('Resposta de embedding sem vetor.');
        }

        if (count($vector) !== $this->dimensions) {
            throw new EmbeddingException(sprintf('Embedding com %d dimensões; NEWS_EMBEDDINGS_DIMENSIONS espera %d.', count($vector), $this->dimensions));
        }

        return FakeEmbedder::normalize(array_map('floatval', array_values($vector)));
    }
}
