<?php

namespace App\Jobs;

use App\Services\Shared\Rag\Indexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rewrite the knowledge-base documents affected by one change.
 *
 * Queued rather than inline because re-indexing calls the embedding API, and no
 * user should wait on that while accepting a booking or recording a payment.
 * The project already runs a queue worker alongside the dev server, so this
 * costs no new infrastructure.
 */
class ReindexRagDocuments implements ShouldQueue
{
    use Queueable;

    public function __construct(
        /** booking | billboard | owner | payout */
        public readonly string $kind,
        public readonly int $id,
    ) {}

    /**
     * Collapses duplicate work: saving a booking twice in one request queues
     * one job, not two.
     */
    public function uniqueId(): string
    {
        return $this->kind.':'.$this->id;
    }

    public function handle(Indexer $indexer): void
    {
        match ($this->kind) {
            'booking' => $indexer->reindexBooking($this->id),
            'billboard' => $indexer->reindexBillboard($this->id),
            'owner' => $indexer->reindexOwnerEarnings($this->id),
            'payout' => $indexer->reindexPayout($this->id),
            default => null,
        };
    }
}
