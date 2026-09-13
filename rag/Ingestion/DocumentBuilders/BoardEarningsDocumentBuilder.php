<?php

namespace Rag\Ingestion\DocumentBuilders;

use App\Models\User;
use App\Services\Owner\OwnerLedgerService;
use Rag\Ingestion\DocumentDraft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pre-computed earnings summaries, one per board per month plus one lifetime
 * total per board. Owner-only, pinned to the owner's account.
 *
 * These exist because of the one thing plain retrieval cannot do. "Which of my
 * boards earned the most this quarter" is an aggregate: answering it from raw
 * payment rows would mean retrieving every one of them and adding up, and
 * top-K hands over six. Doing the arithmetic at ingestion turns the aggregate
 * into a document that can simply be found.
 *
 * The figures come from OwnerLedgerService - the same ledger behind the
 * Transactions page - so a retrieved summary can never disagree with the
 * dashboard the owner is looking at.
 */
class BoardEarningsDocumentBuilder implements DocumentBuilder
{
    public function __construct(
        private readonly OwnerLedgerService $ledger,
        private readonly int $monthsOfHistory,
    ) {}

    public function sourceType(): string
    {
        return 'board_earnings';
    }

    public function build(): iterable
    {
        foreach (User::query()->where('role', 'owner')->cursor() as $owner) {
            yield from $this->forOwner($owner->id);
        }
    }

    /**
     * @return array<int, DocumentDraft>
     */
    public function forOwner(int $ownerId): array
    {
        $transactions = $this->ledger->forOwner($ownerId)['transactions'];

        if ($transactions->isEmpty()) {
            return [];
        }

        $cutoff = now()->subMonths($this->monthsOfHistory)->format('Y-m');
        $drafts = [];

        // Month by month - what answers "how did I do in June".
        foreach ($transactions->groupBy(fn (array $row) => $row['billboard_id'].':'.$row['month']) as $key => $rows) {
            [$billboardId, $month] = explode(':', (string) $key);

            if ($month < $cutoff) {
                continue;
            }

            $drafts[] = $this->draft(
                ownerId: $ownerId,
                sourceId: $key,
                title: sprintf('%s earnings, %s', $rows->first()['billboard_title'], $this->monthName($month)),
                period: 'In '.$this->monthName($month),
                billboardTitle: $rows->first()['billboard_title'],
                billboardId: (int) $billboardId,
                rows: $rows,
            );
        }

        // Lifetime per board - what answers "which board earns me the most".
        foreach ($transactions->groupBy('billboard_id') as $billboardId => $rows) {
            $drafts[] = $this->draft(
                ownerId: $ownerId,
                sourceId: $billboardId.':lifetime',
                title: sprintf('%s total earnings to date', $rows->first()['billboard_title']),
                period: 'In total to date',
                billboardTitle: $rows->first()['billboard_title'],
                billboardId: (int) $billboardId,
                rows: $rows,
            );
        }

        return $drafts;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function draft(
        int $ownerId,
        string $sourceId,
        string $title,
        string $period,
        string $billboardTitle,
        int $billboardId,
        Collection $rows,
    ): DocumentDraft {
        $bucket = fn (string $name) => (float) $rows->where('payout_status', $name)->sum('owner_earning');

        $earnings = (float) $rows->sum('owner_earning');
        $lines = [];

        $lines[] = sprintf(
            '%s, the billboard "%s" (reference number %d) took %d booking%s and collected BDT %s from advertisers.',
            $period,
            $billboardTitle,
            $billboardId,
            $rows->pluck('booking_id')->unique()->count(),
            $rows->pluck('booking_id')->unique()->count() === 1 ? '' : 's',
            number_format((float) $rows->sum('amount')),
        );

        $lines[] = sprintf(
            'After the platform commission of BDT %s, the owner earned BDT %s from this board.',
            number_format((float) $rows->sum('platform_cut')),
            number_format($earnings),
        );

        // Spelling the buckets out in the document itself is what stops the
        // model reporting earned money as though it had already been received.
        $lines[] = sprintf(
            'Of that BDT %s: BDT %s has already been paid out to the owner, BDT %s is verified and ready for the next payout run, BDT %s is waiting on the admin to verify the proof of installation, and BDT %s is still in progress because the advertiser owes a balance or no proof has been uploaded yet.',
            number_format($earnings),
            number_format($bucket('paid_out')),
            number_format($bucket('ready')),
            number_format($bucket('awaiting_verification')),
            number_format($bucket('in_progress')),
        );

        return new DocumentDraft(
            sourceType: $this->sourceType(),
            sourceId: $sourceId,
            title: $title,
            content: implode(' ', $lines),
            visibleToUserId: $ownerId,
            visibleToRole: 'owner',
        );
    }

    /** "2026-06" -> "June 2026", so a question asking for June can match it. */
    private function monthName(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)->format('F Y');
    }
}
