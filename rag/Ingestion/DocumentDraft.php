<?php

namespace Rag\Ingestion;

/**
 * One document on its way into the knowledge base, before it has been embedded
 * or stored. Builders produce these; IngestionPipeline decides what has changed
 * and what needs a new vector.
 */
readonly class DocumentDraft
{
    public function __construct(
        public string $sourceType,
        /** Unique within the source type. Composite where the row is not - "12:owner", "4:2026-06". */
        public string $sourceId,
        public string $title,
        public string $content,
        /** Pins the document to exactly one account. Null means shared. */
        public ?int $visibleToUserId = null,
        /** Only meaningful when visibleToUserId is null: 'all', 'client' or 'owner'. */
        public string $visibleToRole = 'all',
    ) {}

    /**
     * What the Indexer compares to decide whether this document needs
     * re-embedding. Visibility is in the hash as well as the text, so a
     * document that changes hands is rewritten rather than left pointing at
     * the previous owner.
     */
    public function hash(): string
    {
        return hash('sha256', implode('|', [
            $this->title,
            $this->content,
            $this->visibleToUserId ?? '',
            $this->visibleToRole,
        ]));
    }

    /** What actually gets embedded - the title gives a bare row some context. */
    public function embeddableText(): string
    {
        return $this->title."\n".$this->content;
    }
}
