<?php

/**
 * The BillboardBD Assistant's retrieval pipeline.
 *
 * Two halves, matching the standard RAG shape:
 *
 *   ingestion  - rows and policy files are rendered as natural-language
 *                documents, embedded, and stored in `rag_documents`
 *   retrieval  - a question is embedded, the store is filtered down to what
 *                THIS user may see, and the closest few documents become the
 *                context the model answers from
 *
 * The knowledge base lives in the app's own PostgreSQL rather than a separate
 * vector service. That is a size decision, not a shortcut: the visibility
 * filter runs before any similarity maths, so a single user is only ever
 * compared against a few hundred vectors. An approximate-nearest-neighbour
 * index (pgvector/HNSW) earns nothing until that number is in the tens of
 * thousands, and can be dropped in behind Retriever without touching anything
 * else if it ever is.
 */
return [
    // Whether a write to a booking, payment, board, proof or payout queues a
    // re-index of the documents it affects. On in the app, off in the test
    // suite so fixtures do not embed themselves.
    'auto_reindex' => (bool) env('RAG_AUTO_REINDEX', true),

    'embeddings' => [
        // voyage  - hosted embedding API, what production should use
        // hashing - deterministic, offline, no network. Tests and local work
        //           run on this so the suite never calls out to anything.
        'driver' => env('RAG_EMBEDDING_DRIVER', 'voyage'),

        'voyage' => [
            'key' => env('VOYAGE_API_KEY'),
            // Confirm the current model id against Voyage's docs before going
            // live; it is a config value precisely so it can move. Whatever it
            // is, it must be multilingual - a Bangla question has to land near
            // an English document.
            'model' => env('VOYAGE_MODEL', 'voyage-3.5'),
            'endpoint' => 'https://api.voyageai.com/v1/embeddings',
            'timeout' => 30,
            // Documents per request when indexing.
            'batch_size' => 64,
        ],

        'hashing' => [
            'dimensions' => 256,
        ],
    ],

    'retrieval' => [
        // hybrid  - vector similarity fused with keyword scoring (recommended)
        // vector  - embeddings only
        // lexical - keywords only, no embedding provider needed at all
        //
        // The two single-method modes are kept so the value of fusion can be
        // measured rather than assumed - run the same questions through each.
        'mode' => env('RAG_RETRIEVAL_MODE', 'hybrid'),

        // How many documents become context. Small on purpose: more context is
        // not more accuracy, and every extra document is tokens paid for.
        'top_k' => (int) env('RAG_TOP_K', 6),

        // Cosine similarity below this is treated as "no match" rather than
        // handed over as weak context for the model to over-trust. Applies to
        // the vector half only; the keyword half self-limits, since a document
        // sharing no query term scores nothing at all.
        'min_score' => (float) env('RAG_MIN_SCORE', 0.15),
    ],

    'ingestion' => [
        // Policy prose is split at this size. Rows are already one document
        // each and are never split.
        'chunk_chars' => 1200,

        // Where the hand-written policy corpus lives, relative to the app base
        // path. Kept beside the rest of the RAG pipeline (rag/) rather than
        // under resources/, since it is corpus data for this system, not a
        // Blade/asset resource.
        'policy_path' => 'rag/policy',

        // How many months of per-board earnings summaries to keep indexed.
        'board_month_history' => 24,
    ],
];
