<?php

namespace Rag\Retrieval;

use App\Models\User;
use Illuminate\Support\Collection;
use Rag\Embeddings\EmbeddingClient;
use Rag\Storage\RagDocument;

/**
 * The whole retrieval pipeline, start to finish: question -> scoped candidates
 * -> two rankings -> top K. This is the class the "Retrieval pipeline" box in
 * the architecture diagram maps to.
 *
 * The order of those steps is the security model. Visibility is applied in SQL
 * first, so scoring only ever runs over documents this user is already entitled
 * to; another actor's booking is not a low-scoring result, it is not a result.
 * Nothing here asks the language model to be discreet.
 *
 * Ranking is hybrid. The question is scored twice - once by vector similarity,
 * once by keyword overlap - and the two rankings are fused. They fail in
 * opposite directions: embeddings generalise but blur exact terms, keywords
 * nail exact terms but cannot paraphrase. A board name or a reference number
 * that the vector model smears across similar boards is precisely what the
 * keyword side pins down, and vice versa.
 *
 * Both run in PHP rather than in an index, which follows from the scoping
 * above: after filtering, one user has a few hundred candidates at most.
 * Swapping in pgvector or Postgres full-text later means changing this class
 * and nothing else.
 */
class RetrievalPipeline
{
    /**
     * Fusion constant. Reciprocal rank fusion combines the two rankings by
     * POSITION rather than by score, which is the point: cosine similarity and
     * BM25 live on different scales, and any attempt to weight them directly
     * needs recalibrating every time either side changes. 60 is the value the
     * original RRF paper settled on and it is not sensitive.
     */
    private const RRF_K = 60;

    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly LexicalScorer $lexical,
        private readonly int $topK,
        private readonly float $minScore,
        /** hybrid | vector | lexical - vector and lexical exist to measure what fusion is worth. */
        private readonly string $mode = 'hybrid',
    ) {}

    /**
     * @return array<int, array{title: string, content: string, source_type: string, score: float, vector_score: float, lexical_score: float}>
     */
    public function retrieve(User $user, string $question, ?int $topK = null): array
    {
        $question = trim($question);
        if ($question === '') {
            return [];
        }

        $candidates = RagDocument::query()
            ->visibleTo($user)
            ->get(['id', 'title', 'content', 'source_type', 'embedding', 'embedding_model']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $vectorScores = $this->mode === 'lexical' ? [] : $this->vectorScores($question, $candidates);
        $lexicalScores = $this->mode === 'vector' ? [] : $this->lexicalScores($question, $candidates);

        if ($vectorScores === [] && $lexicalScores === []) {
            return [];
        }

        $fused = $this->fuse($vectorScores, $lexicalScores);

        return $candidates
            ->filter(fn (RagDocument $document) => isset($fused[$document->id]))
            ->map(fn (RagDocument $document) => [
                'title' => $document->title,
                'content' => $document->content,
                'source_type' => $document->source_type,
                'score' => round($fused[$document->id], 5),
                // Kept on the result so a weak answer can be traced to which
                // half of retrieval found the passage - and so the two modes
                // can be compared when tuning.
                'vector_score' => round($vectorScores[$document->id] ?? 0.0, 4),
                'lexical_score' => round($lexicalScores[$document->id] ?? 0.0, 4),
            ])
            ->sortByDesc('score')
            ->take($topK ?? $this->topK)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, RagDocument>  $candidates
     * @return array<int, float>
     */
    private function vectorScores(string $question, Collection $candidates): array
    {
        // Vectors made by a different embedding model sit in an unrelated
        // space, and scoring across them returns confident nonsense. They are
        // skipped rather than compared - which, in hybrid mode, means a model
        // switch degrades to keyword-only retrieval instead of returning
        // nothing at all until a re-index finishes.
        $usable = $candidates->filter(
            fn (RagDocument $document) => $document->embedding
                && $document->embedding_model === $this->embeddings->model()
        );

        if ($usable->isEmpty()) {
            return [];
        }

        $queryVector = $this->embeddings->embedOne($question);
        $scores = [];

        foreach ($usable as $document) {
            $score = $this->cosine($queryVector, $document->embedding ?? []);

            // Below the floor is "no match", not "the closest thing I have" -
            // weak context is exactly what a model over-trusts.
            if ($score >= $this->minScore) {
                $scores[$document->id] = $score;
            }
        }

        return $scores;
    }

    /**
     * @param  Collection<int, RagDocument>  $candidates
     * @return array<int, float>
     */
    private function lexicalScores(string $question, Collection $candidates): array
    {
        return $this->lexical->score(
            $question,
            $candidates->mapWithKeys(fn (RagDocument $d) => [$d->id => $d->title.' '.$d->content])->all(),
        );
    }

    /**
     * Reciprocal rank fusion: each ranking contributes 1/(k + position) to
     * every document it ranks. A document both halves like beats one that
     * either half loves, which is the behaviour worth having - agreement
     * between two methods that fail differently is a strong signal.
     *
     * @param  array<int, float>  $vectorScores
     * @param  array<int, float>  $lexicalScores
     * @return array<int, float>
     */
    private function fuse(array $vectorScores, array $lexicalScores): array
    {
        $fused = [];

        foreach ([$vectorScores, $lexicalScores] as $ranking) {
            arsort($ranking);
            $position = 0;

            foreach (array_keys($ranking) as $id) {
                $position++;
                $fused[$id] = ($fused[$id] ?? 0.0) + 1 / (self::RRF_K + $position);
            }
        }

        return $fused;
    }

    /**
     * Cosine similarity, computed in full rather than as a bare dot product:
     * some embedding models return unit vectors and some do not, and assuming
     * it silently skews every score when a provider changes.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = $magnitudeA = $magnitudeB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i];
            $dot += $value * $other;
            $magnitudeA += $value * $value;
            $magnitudeB += $other * $other;
        }

        if ($magnitudeA <= 0.0 || $magnitudeB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
