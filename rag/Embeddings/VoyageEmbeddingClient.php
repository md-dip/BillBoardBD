<?php

namespace Rag\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Hosted embeddings from Voyage AI.
 *
 * Anthropic does not serve an embeddings endpoint, so the pipeline pairs Claude
 * (the answering model) with a separate embedding model. Voyage is the
 * provider Anthropic points at; swapping it means writing one more class
 * against EmbeddingClient and changing rag.embeddings.driver - nothing else in
 * the pipeline knows which service produced a vector.
 *
 * Whichever model is configured must be multilingual: a question typed in
 * Bangla has to land near an English document about the same booking.
 */
class VoyageEmbeddingClient implements EmbeddingClient
{
    /**
     * Cached so dimensions() does not have to guess before anything has been
     * embedded - the API reports the true width on the first call.
     */
    private ?int $dimensions = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $endpoint,
        private readonly int $timeout,
        private readonly int $batchSize,
    ) {}

    public function model(): string
    {
        return 'voyage:'.$this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions ?? 1024;
    }

    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0];
    }

    public function embed(array $texts): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException(
                'No VOYAGE_API_KEY set. Set one, or run with RAG_EMBEDDING_DRIVER=hashing for offline work.'
            );
        }

        if ($texts === []) {
            return [];
        }

        $vectors = [];

        // Batched because indexing sends thousands of documents and one giant
        // request would time out. Order is preserved across batches, which the
        // caller relies on to pair vectors back to documents.
        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->retry(3, 500, throw: false)
                ->post($this->endpoint, [
                    'input' => array_values($batch),
                    'model' => $this->model,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Voyage embeddings failed ({$response->status()}): ".$response->body()
                );
            }

            $data = $response->json('data');

            if (! is_array($data) || count($data) !== count($batch)) {
                throw new RuntimeException('Voyage returned a different number of vectors than texts sent.');
            }

            // The API documents results as ordered, but it also returns an
            // explicit index on each - sorting by it costs nothing and removes
            // the assumption entirely.
            usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            foreach ($data as $item) {
                $vector = array_map('floatval', $item['embedding'] ?? []);
                $this->dimensions ??= count($vector);
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }
}
