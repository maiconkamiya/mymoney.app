<?php

namespace App\News\Embeddings;

/**
 * Deterministic bag-of-words embedder (feature hashing) for tests and for
 * running the app without a provider key. Texts that share many words end up
 * close in cosine similarity, which is enough to exercise deduplication.
 */
class FakeEmbedder implements Embedder
{
    public function __construct(private readonly int $dimensions = 64) {}

    public function embed(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }

            $vector[crc32($word) % $this->dimensions] += 1.0;
        }

        return self::normalize(array_values($vector));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        if ($norm == 0.0) {
            return $vector;
        }

        return array_map(fn (float $v): float => $v / $norm, $vector);
    }
}
