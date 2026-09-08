<?php

namespace App\Services\Shared\Rag\Embeddings;

/**
 * Turns text into vectors - the "Embedding API" step of the pipeline, used on
 * both sides of it: once per document at ingestion, once per question at
 * retrieval. The same client must serve both, or the question would land in a
 * different space from the documents and every score would be meaningless.
 *
 * Implementations report the model they represent so stored vectors can be
 * matched against the one currently in use, and stale ones ignored rather than
 * silently compared.
 */
interface EmbeddingClient
{
    /** Identifier stored alongside every vector this client produces. */
    public function model(): string;

    public function dimensions(): int;

    /**
     * Embed a batch of texts, returning one vector per input, in order.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array;

    /** Convenience for the single-text case - a user's question. */
    public function embedOne(string $text): array;
}
