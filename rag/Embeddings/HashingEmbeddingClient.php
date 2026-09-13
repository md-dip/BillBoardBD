<?php

namespace Rag\Embeddings;

/**
 * A deterministic, offline embedder - no API key, no network, same vector every
 * time for the same text.
 *
 * This is what the test suite and offline development run on. Tests that had to
 * call a paid API would be slow, flaky and expensive, and would stop working on
 * a plane; with this driver the entire ingestion and retrieval pipeline can be
 * exercised end to end for free.
 *
 * It is a hashing vectoriser over words and character trigrams, not a learned
 * model: it captures LEXICAL overlap ("payout" matches "payout") but knows
 * nothing of meaning ("earnings" does not match "revenue"). That is enough to
 * prove the pipeline moves documents around correctly, and nowhere near enough
 * for production - hence the separate driver rather than a fallback.
 */
class HashingEmbeddingClient implements EmbeddingClient
{
    public function __construct(private readonly int $dimensions = 256) {}

    public function model(): string
    {
        return 'hashing:v1-'.$this->dimensions;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0];
    }

    public function embed(array $texts): array
    {
        return array_map(fn (string $text) => $this->vectorise($text), array_values($texts));
    }

    /**
     * @return array<int, float>
     */
    private function vectorise(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $normalised = mb_strtolower(trim($text));

        // \p{L}\p{N} rather than \w so Bangla script tokenises as words too,
        // instead of collapsing into one unsplittable blob.
        $words = preg_split('/[^\p{L}\p{N}]+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            $this->add($vector, 'w:'.$word, 1.0);

            // Character trigrams give partial credit for near-misses - plurals,
            // and the inconsistent spelling of Bangla written in Latin script.
            $length = mb_strlen($word);
            for ($i = 0; $i + 3 <= $length; $i++) {
                $this->add($vector, 'g:'.mb_substr($word, $i, 3), 0.4);
            }
        }

        return $this->normalise($vector);
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function add(array &$vector, string $token, float $weight): void
    {
        $hash = crc32($token);
        $bucket = $hash % $this->dimensions;
        // The low bit picks a sign, so unrelated tokens landing in the same
        // bucket are as likely to cancel as to reinforce.
        $vector[$bucket] += ($hash & 1) ? $weight : -$weight;
    }

    /**
     * Unit length, so a dot product IS the cosine similarity and the retriever
     * never has to divide by magnitudes.
     *
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    private function normalise(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(fn (float $v) => $v / $magnitude, $vector);
    }
}
