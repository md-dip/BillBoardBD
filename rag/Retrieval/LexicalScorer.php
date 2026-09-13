<?php

namespace Rag\Retrieval;

/**
 * BM25-style keyword scoring - the lexical half of hybrid retrieval.
 *
 * It exists to cover what embeddings are worst at. A vector model generalises
 * ("revenue" ~ "earnings") but blurs exact tokens, so a question naming a
 * specific board, a reference number or a term of art like "proof of posting"
 * can rank below a vaguely related passage. Keyword scoring is the opposite:
 * useless at paraphrase, excellent at exactly those. Fusing the two is worth
 * more than either alone, especially on a corpus this small.
 *
 * Deliberately computed in PHP rather than in Postgres full-text search. The
 * candidate set is already scoped to a few hundred documents before scoring, so
 * there is nothing to gain from an index - and one implementation means the
 * test suite on SQLite exercises the same code that runs in production, instead
 * of a second path nobody tests.
 *
 * Stemming is approximated by prefix matching rather than a real stemmer: a
 * query token and a document token that share a prefix of at least four
 * characters score a partial hit. That is what lets "how did my boards EARN"
 * find a document about "EARNINGS", which is the exact miss that motivated
 * hybrid retrieval here.
 */
class LexicalScorer
{
    /** Words carrying no signal - they appear in nearly every document. */
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'but', 'by', 'can', 'did', 'do',
        'does', 'for', 'from', 'get', 'had', 'has', 'have', 'how', 'i', 'if', 'in', 'is',
        'it', 'its', 'me', 'my', 'no', 'not', 'of', 'on', 'or', 'so', 'that', 'the', 'their',
        'them', 'then', 'there', 'they', 'this', 'to', 'was', 'were', 'what', 'when', 'where',
        'which', 'who', 'why', 'will', 'with', 'you', 'your',
    ];

    private const MIN_PREFIX = 4;

    public function __construct(
        /** Term-frequency saturation - how fast repeats stop adding value. */
        private readonly float $k1 = 1.4,
        /** How hard longer documents are penalised. */
        private readonly float $b = 0.75,
    ) {}

    /**
     * Score every document against the query.
     *
     * @param  array<int|string, string>  $documents  text keyed by document id
     * @return array<int|string, float> scores keyed by the same ids, unscored documents omitted
     */
    public function score(string $query, array $documents): array
    {
        $queryTokens = array_unique($this->tokenise($query));

        if ($queryTokens === [] || $documents === []) {
            return [];
        }

        // Token counts per document, built once and reused for every term.
        $counts = [];
        $lengths = [];
        foreach ($documents as $id => $text) {
            $tokens = $this->tokenise($text);
            $counts[$id] = array_count_values($tokens);
            $lengths[$id] = max(count($tokens), 1);
        }

        $averageLength = array_sum($lengths) / count($lengths);

        // Term frequency per document, and in how many documents each term
        // appears at all - the latter is what makes a rare word count more.
        $termFrequencies = [];
        $documentFrequency = [];

        foreach ($queryTokens as $term) {
            $documentFrequency[$term] = 0;

            foreach ($counts as $id => $tokenCounts) {
                $frequency = $this->frequencyOf($term, $tokenCounts);

                if ($frequency > 0.0) {
                    $termFrequencies[$id][$term] = $frequency;
                    $documentFrequency[$term]++;
                }
            }
        }

        $total = count($documents);
        $scores = [];

        foreach ($termFrequencies as $id => $terms) {
            $score = 0.0;

            foreach ($terms as $term => $frequency) {
                // Standard BM25 IDF. A term in almost every document adds
                // almost nothing; a term in one document adds a lot.
                $idf = log(1 + (($total - $documentFrequency[$term] + 0.5) / ($documentFrequency[$term] + 0.5)));

                $score += $idf * (($frequency * ($this->k1 + 1))
                    / ($frequency + $this->k1 * (1 - $this->b + $this->b * ($lengths[$id] / $averageLength))));
            }

            if ($score > 0.0) {
                $scores[$id] = $score;
            }
        }

        return $scores;
    }

    /**
     * How strongly one query term is present in one document. An exact token
     * counts in full; a token sharing a long prefix counts for half, which is
     * the cheap stand-in for stemming described above.
     *
     * @param  array<string, int>  $tokenCounts
     */
    private function frequencyOf(string $term, array $tokenCounts): float
    {
        $frequency = (float) ($tokenCounts[$term] ?? 0);

        if (mb_strlen($term) < self::MIN_PREFIX) {
            return $frequency;
        }

        foreach ($tokenCounts as $token => $count) {
            $token = (string) $token;

            if ($token === $term || mb_strlen($token) < self::MIN_PREFIX) {
                continue;
            }

            // Either direction: "earn" should find "earnings", and "earnings"
            // should find "earn".
            if (str_starts_with($token, $term) || str_starts_with($term, $token)) {
                $frequency += 0.5 * $count;
            }
        }

        return $frequency;
    }

    /**
     * @return array<int, string>
     */
    private function tokenise(string $text): array
    {
        // \p{L}\p{N} rather than \w so Bangla script tokenises as words too.
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) > 1 && ! in_array($token, self::STOPWORDS, true),
        ));
    }
}
