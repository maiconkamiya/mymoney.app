<?php

namespace App\News\Embeddings;

interface Embedder
{
    /**
     * Turn a text into an L2-normalised vector so cosine similarity is a dot product.
     *
     * @return list<float>
     *
     * @throws EmbeddingException
     */
    public function embed(string $text): array;
}
