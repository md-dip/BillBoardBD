<?php

namespace App\Services\Owner;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What the BillboardBD Assistant can look up on behalf of a signed-in owner.
 *
 * As with the client tools, the authenticated User is the only source of
 * identity: no schema below accepts an owner id, and every query filters on
 * `billboards.owner_id` in SQL. An owner asking about a board they do not own
 * gets an empty result, not a refusal the model could be talked out of.
 *
 * Money answers come from OwnerLedgerService - the same ledger behind the
 * Transactions page - so the assistant can never quote a figure the owner's own
 * dashboard would contradict.
 */
class AssistantTools
{
    /**
     * What each of the four earning buckets means, handed to the model with
     * every performance answer. "Earned" is not "in your bank", and an owner
     * asking about sales needs to be told which one they are being given.
     */
    public const BUCKET_MEANINGS = [
        'in_progress' => 'Earned, but nothing is with the admin yet - the client still owes the balance, or proof of installation has not been uploaded.',
        'awaiting_verification' => 'Proof of installation uploaded; the admin has not verified it yet.',
        'ready_for_payout' => 'Admin verified the installation. Payable on the next payout run.',
        'paid_out' => 'Already sent to the owner - this always equals their payout history.',
    ];

    public function __construct(
        private readonly OwnerLedgerService $ledger,
        private readonly int $maxRows,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'get_my_billboards',
                'description' => "List the signed-in owner's own billboards, including where each one is in the listing review process and when its permit expires.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'listing_status' => ['type' => 'string', 'description' => 'Optional filter: pending_payment, pending_review, approved, or rejected.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_board_performance',
                'description' => "Earnings for the signed-in owner's boards, broken down per billboard and split into the four buckets money passes through (in_progress, awaiting_verification, ready_for_payout, paid_out). This is the tool for any question about sales, revenue, earnings, or how a board is doing. Amounts are in Bangladeshi Taka. Always tell the user which bucket a figure came from - earned money is not the same as money already paid out.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'billboard_id' => ['type' => 'integer', 'description' => 'Limit to one board. Omit to cover every board the owner has.'],
                        'from' => ['type' => 'string', 'description' => 'Start of the period, YYYY-MM-DD. Filters on when the money was collected, not on the campaign dates.'],
                        'to' => ['type' => 'string', 'description' => 'End of the period, YYYY-MM-DD.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_booking_requests',
                'description' => "Booking requests on the signed-in owner's boards, newest first - who wants to advertise, for which dates, and what stage the request is at. Use it for questions about requests waiting on the owner, upcoming campaigns, or who booked a board.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Optional filter, e.g. pending_owner_approval for requests waiting on the owner to accept.'],
                        'billboard_id' => ['type' => 'integer', 'description' => 'Limit to requests on one board.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_my_payouts',
                'description' => "The signed-in owner's payout history - money the platform has actually sent them - plus the amount currently ready to be paid out.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
                    'required' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(string $name, array $input, User $user): array
    {
        return match ($name) {
            'get_my_billboards' => $this->myBillboards($input, $user),
            'get_board_performance' => $this->boardPerformance($input, $user),
            'get_booking_requests' => $this->bookingRequests($input, $user),
            'get_my_payouts' => $this->myPayouts($user),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function myBillboards(array $input, User $user): array
    {
        $query = Billboard::query()
            ->where('owner_id', $user->id)
            ->when(
                trim((string) ($input['listing_status'] ?? '')) !== '',
                fn (Builder $q) => $q->where('listing_status', trim((string) $input['listing_status']))
            );

        // True count and returned count kept apart - see the note in the client
        // tools: one number and the model reports the row cap as the total.
        $total = (clone $query)->count();

        $boards = $query->orderBy('title')->limit($this->maxRows)->get();

        return [
            'billboards_found' => $total,
            'showing' => $boards->count(),
            'billboards' => $boards->map(fn (Billboard $b) => [
                'billboard_id' => $b->id,
                'title' => $b->title,
                'address' => $b->address,
                'type' => $b->type,
                'size' => $b->size,
                'pricing_mode' => $b->pricing_mode,
                'daily_rate_bdt' => (float) $b->daily_rate,
                'monthly_rate_bdt' => (float) $b->monthly_rate,
                'rating' => (float) $b->rating,
                'listing_status' => $b->listing_status,
                'listing_rejection_reason' => $b->listing_rejection_reason,
                'permit_expires' => $b->permit_expiry_date?->toDateString(),
                'permit_expired' => $b->permit_expiry_date?->isPast(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function boardPerformance(array $input, User $user): array
    {
        $billboardId = isset($input['billboard_id']) ? (int) $input['billboard_id'] : null;

        // Ownership is re-checked here rather than trusted from the argument:
        // an id the owner does not hold must not silently fall through to an
        // unfiltered ledger covering every board they DO hold.
        if ($billboardId !== null) {
            $owns = Billboard::query()
                ->where('owner_id', $user->id)
                ->where('id', $billboardId)
                ->exists();

            if (! $owns) {
                return ['error' => 'No billboard with that id belongs to you.'];
            }
        }

        $from = $this->dateString($input['from'] ?? null);
        $to = $this->dateString($input['to'] ?? null);

        $ledger = $this->ledger->forOwner($user->id, $billboardId, $from, $to);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'currency' => 'BDT',
            'totals' => $ledger['totals'],
            'bucket_meanings' => self::BUCKET_MEANINGS,
            'per_billboard' => $this->ledger->perBillboard($user->id, $billboardId, $from, $to),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function bookingRequests(array $input, User $user): array
    {
        $query = Booking::query()
            ->whereHas('billboard', fn (Builder $q) => $q->where('owner_id', $user->id))
            ->when(
                trim((string) ($input['status'] ?? '')) !== '',
                fn (Builder $q) => $q->where('status', trim((string) $input['status']))
            )
            ->when(
                isset($input['billboard_id']),
                fn (Builder $q) => $q->where('billboard_id', (int) $input['billboard_id'])
            );

        $total = (clone $query)->count();

        $bookings = $query
            ->with(['billboard:id,title', 'user:id,name'])
            ->orderByDesc('created_at')
            ->limit($this->maxRows)
            ->get();

        return [
            'requests_found' => $total,
            'showing' => $bookings->count(),
            'requests' => $bookings->map(fn (Booking $b) => [
                'booking_id' => $b->id,
                'billboard_id' => $b->billboard_id,
                'billboard_title' => $b->billboard?->title,
                'client_name' => $b->user?->name,
                'brand_name' => $b->brand_name,
                'ad_category' => $b->ad_category,
                'start_date' => $b->start_date?->toDateString(),
                'end_date' => $b->end_date?->toDateString(),
                'status' => $b->status,
                'total_amount_bdt' => (float) $b->total_amount,
                'needs_owner_action' => $b->status === 'pending_owner_approval',
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function myPayouts(User $user): array
    {
        $query = Payout::query()->where('owner_id', $user->id);

        $total = (clone $query)->count();
        // Summed over every payout, not just the page of them listed below -
        // "how much have I been paid" must not quietly mean "of the last 25".
        $lifetime = (float) (clone $query)->sum('amount');

        $payouts = $query->orderByDesc('paid_at')->limit($this->maxRows)->get();

        $totals = $this->ledger->forOwner($user->id)['totals'];

        return [
            'currency' => 'BDT',
            'payouts_found' => $total,
            'showing' => $payouts->count(),
            'total_paid_out' => round($lifetime, 2),
            'ready_for_payout_now' => $totals['ready_for_payout'],
            'still_awaiting_admin_verification' => $totals['awaiting_verification'],
            'payouts' => $payouts->map(fn (Payout $p) => [
                'payout_id' => $p->id,
                'amount_bdt' => (float) $p->amount,
                'method' => $p->method,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toDateTimeString(),
                'note' => $p->note,
            ])->all(),
        ];
    }

    private function dateString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
