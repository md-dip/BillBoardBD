<?php

namespace Rag\Ingestion\DocumentBuilders;

use Rag\Ingestion\DocumentDraft;

/**
 * Step 2 of ingestion - "Extract" + "Document builder" in the pipeline diagram.
 * Turns one kind of source - a policy file, a table - into documents.
 *
 * The job of every builder is the same: write what a person would write. A row
 * pasted in as JSON embeds badly, because the question "how much did Banani
 * earn in June" looks nothing like `{"billboard_id":4,"amount":45000}`. Written
 * as a sentence, the question and the document land near each other, which is
 * the entire mechanism retrieval runs on.
 */
interface DocumentBuilder
{
    /** Namespace for this builder's documents, e.g. 'booking'. */
    public function sourceType(): string;

    /**
     * Every document this builder is responsible for.
     *
     * @return iterable<DocumentDraft>
     */
    public function build(): iterable;
}
