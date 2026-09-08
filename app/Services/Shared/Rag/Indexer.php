<?php

namespace App\Services\Shared\Rag;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payout;
use App\Models\RagDocument;
use App\Services\Shared\Rag\DocumentBuilders\BillboardDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\BoardEarningsDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\BookingDocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\DocumentBuilder;
use App\Services\Shared\Rag\DocumentBuilders\PayoutDocumentBuilder;
use App\Services\Shared\Rag\Embeddings\EmbeddingClient;

/**
 * The ingestion pipeline: sources -> documents -> vectors -> knowledge base.
 *
 * Two things keep it cheap enough to run often. Each draft carries a hash of
 * its own text and visibility, so a document that has not moved is skipped
 * without being re-embedded; and documents are embedded in batches rather than
 * one request each.
 *
 * A full rebuild is the command; the observers call the narrow reindex methods
 * at the bottom, which is what keeps the index honest between rebuilds. That
 * matters more here than in a document-based RAG: a PDF sits still, but a
 * booking changes stage several times a week, and a stale document would have
 * the assistant confidently describing a stage the booking has already left.
 */
class Indexer
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly BillboardDocumentBuilder $billboards,
        private readonly BookingDocumentBuilder $bookings,
        private readonly BoardEarningsDocumentBuilder $earnings,
        private readonly PayoutDocumentBuilder $payouts,
    ) {}

    /**
     * Rebuild the whole knowledge base.
     *
     * @param  array<int, DocumentBuilder>  $builders
     * @return array<string, array{written: int, unchanged: int, removed: int}>
     */
    public function rebuild(array $builders, bool $force = false): array
    {
        $report = [];

        foreach ($builders as $builder) {
            $report[$builder->sourceType()] = $this->rebuildSource($builder, $force);
        }

        return $report;
    }

    /**
     * @return array{written: int, unchanged: int, removed: int}
     */
    private function rebuildSource(DocumentBuilder $builder, bool $force): array
    {
        $type = $builder->sourceType();

        // One query for what is already indexed, so the per-document check
        // below is an array lookup rather than a round trip each time.
        $existing = RagDocument::query()
            ->where('source_type', $type)
            ->get(['source_id', 'content_hash', 'embedding_model'])
            ->keyBy('source_id');

        $seen = [];
        $stale = [];
        $unchanged = 0;

        foreach ($builder->build() as $draft) {
            $seen[] = $draft->sourceId;
            $current = $existing->get($draft->sourceId);

            if (! $force && $current
                && $current->content_hash === $draft->hash()
                && $current->embedding_model === $this->embeddings->model()) {
                $unchanged++;

                continue;
            }

            $stale[] = $draft;
        }

        $written = $this->embedAndStore($stale);

        // Anything this builder no longer produces - a booking deleted, a board
        // rejected - must leave the knowledge base, or the assistant will keep
        // answering from it.
        $removed = RagDocument::query()
            ->where('source_type', $type)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('source_id', $seen))
            ->delete();

        return ['written' => $written, 'unchanged' => $unchanged, 'removed' => $removed];
    }

    /**
     * Embed a set of drafts and upsert them.
     *
     * @param  array<int, DocumentDraft>  $drafts
     */
    public function embedAndStore(array $drafts): int
    {
        if ($drafts === []) {
            return 0;
        }

        $written = 0;

        foreach (array_chunk($drafts, 64) as $batch) {
            $vectors = $this->embeddings->embed(
                array_map(fn (DocumentDraft $draft) => $draft->embeddableText(), $batch)
            );

            foreach ($batch as $i => $draft) {
                RagDocument::query()->updateOrCreate(
                    ['source_type' => $draft->sourceType, 'source_id' => $draft->sourceId],
                    [
                        'title' => $draft->title,
                        'content' => $draft->content,
                        'visible_to_user_id' => $draft->visibleToUserId,
                        'visible_to_role' => $draft->visibleToRole,
                        'embedding' => $vectors[$i] ?? null,
                        'embedding_model' => $this->embeddings->model(),
                        'dimensions' => count($vectors[$i] ?? []),
                        'content_hash' => $draft->hash(),
                        'indexed_at' => now(),
                    ],
                );
                $written++;
            }
        }

        return $written;
    }

    // ------------------------------------------------- incremental reindexing

    /**
     * A booking moved. Rewrites both of its documents and the earnings
     * summaries for the board it sits on, since a stage change can move money
     * between buckets without touching a payment row.
     */
    public function reindexBooking(int $bookingId): void
    {
        $booking = Booking::query()
            ->with(['billboard:id,title,address,owner_id', 'user:id,name', 'payments'])
            ->find($bookingId);

        if (! $booking) {
            RagDocument::query()
                ->where('source_type', 'booking')
                ->whereIn('source_id', [$bookingId.':client', $bookingId.':owner'])
                ->delete();

            return;
        }

        $this->embedAndStore($this->bookings->forBooking($booking));

        if ($booking->billboard?->owner_id) {
            $this->reindexOwnerEarnings($booking->billboard->owner_id);
        }
    }

    /** A board was edited, approved or rejected. */
    public function reindexBillboard(int $billboardId): void
    {
        $billboard = Billboard::query()->find($billboardId);

        // Only approved boards are indexed, so an unapproved one is a removal.
        if (! $billboard || $billboard->listing_status !== 'approved') {
            RagDocument::query()
                ->where('source_type', 'billboard')
                ->where('source_id', (string) $billboardId)
                ->delete();

            return;
        }

        $this->embedAndStore([
            new DocumentDraft(
                sourceType: 'billboard',
                sourceId: (string) $billboard->id,
                title: $billboard->title,
                content: $this->billboards->describe($billboard),
                visibleToUserId: null,
                visibleToRole: 'all',
            ),
        ]);
    }

    /** Money moved for this owner - rewrite their earnings summaries. */
    public function reindexOwnerEarnings(int $ownerId): void
    {
        $drafts = $this->earnings->forOwner($ownerId);
        $this->embedAndStore($drafts);

        // Summaries for months that no longer have any money in them have to
        // go, or an old figure lingers after a refund.
        RagDocument::query()
            ->where('source_type', 'board_earnings')
            ->where('visible_to_user_id', $ownerId)
            ->when(
                $drafts !== [],
                fn ($query) => $query->whereNotIn('source_id', array_map(fn ($d) => $d->sourceId, $drafts)),
            )
            ->delete();
    }

    public function reindexPayout(int $payoutId): void
    {
        $payout = Payout::query()->find($payoutId);

        if (! $payout) {
            RagDocument::query()
                ->where('source_type', 'payout')
                ->where('source_id', (string) $payoutId)
                ->delete();

            return;
        }

        $this->embedAndStore([$this->payouts->forPayout($payout)]);
        $this->reindexOwnerEarnings($payout->owner_id);
    }
}
