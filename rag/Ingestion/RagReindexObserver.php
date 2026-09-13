<?php

namespace Rag\Ingestion;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\ProofOfPosting;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the knowledge base honest between full rebuilds.
 *
 * This is the difference between RAG over PDFs and RAG over a live system. A
 * PDF sits still; a booking moves from "waiting for the owner" to "confirmed"
 * to "active" within a week, and money changes bucket every time a payment
 * lands or an admin verifies a proof. A document written last night would have
 * the assistant describing a stage the booking has already left - stated
 * confidently, because the model has no way to know the text is old.
 *
 * So every write that could change what a document says queues a re-index of
 * just the documents it touches. One observer serves several models: the model
 * instance says which reindex applies.
 */
class RagReindexObserver
{
    public function saved(Model $model): void
    {
        $this->queue($model);
    }

    public function deleted(Model $model): void
    {
        $this->queue($model);
    }

    private function queue(Model $model): void
    {
        // Off during tests unless a test switches it on, so the suite is not
        // embedding documents on every fixture it creates.
        if (! config('rag.auto_reindex')) {
            return;
        }

        $job = match (true) {
            $model instanceof Booking => new ReindexRagDocuments('booking', $model->id),
            $model instanceof Billboard => new ReindexRagDocuments('billboard', $model->id),
            // A payment changes what a booking owes AND which bucket its money
            // sits in, so it re-indexes through the booking.
            $model instanceof Payment => new ReindexRagDocuments('booking', (int) $model->booking_id),
            // Verifying a proof is what moves money from "awaiting admin" to
            // "ready for payout" without any row on the booking changing.
            $model instanceof ProofOfPosting => new ReindexRagDocuments('booking', (int) $model->booking_id),
            $model instanceof Payout => new ReindexRagDocuments('payout', $model->id),
            default => null,
        };

        if ($job) {
            dispatch($job);
        }
    }
}
