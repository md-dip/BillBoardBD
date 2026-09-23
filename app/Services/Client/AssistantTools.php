<?php

namespace App\Services\Client;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;


class AssistantTools
{

    private const OCCUPYING_STATUSES = [
        'held', 'pending_payment', 'pending_admin_review', 'pending_owner_approval',
        'confirmed', 'paid_in_full', 'pending_proof_review', 'active',
    ];


    public const STATUS_MEANINGS = [
        'held' => 'Dates are locked for you for a few minutes while you finish the request. Nothing is paid yet.',
        'pending_payment' => 'Campaign details are in; the advance payment has not gone through yet.',
        'pending_admin_review' => 'Advance paid. The BillboardBD team is checking the request and the board permit.',
        'pending_owner_approval' => 'Admin approved it. Waiting on the billboard owner to accept.',
        'confirmed' => 'Both approvals are in. Pay the remaining balance before the due date.',
        'paid_in_full' => 'Paid in full. Waiting for the owner to post the ad and upload proof.',
        'pending_proof_review' => 'The owner uploaded proof of installation; admin is verifying it.',
        'active' => 'Verified and live - the campaign is running.',
        'rejected' => 'The request was turned down. Any advance paid is refunded automatically.',
        'expired' => 'The hold ran out before the request was completed. The dates were released.',
        'cancelled' => 'The booking was cancelled.',
    ];

    public function __construct(private readonly int $maxRows) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'search_billboards',
                'description' => 'Search billboards that are approved and publicly listed. Use this for any question about finding, comparing, or pricing advertising space. Pass available_from and available_to whenever the user mentions dates, so boards already booked for that period are excluded. Prices are in Bangladeshi Taka (BDT). When a budget you passed matches nothing, the result also carries cheapest_above_budget - the closest boards that fit every other filter. Always show those to the user rather than replying that nothing was found.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => 'Free text matched against the billboard title and address, e.g. "Gulshan circle" or "LED".'],
                        'area' => ['type' => 'string', 'description' => 'Neighbourhood or road matched against the address only, e.g. "Banani", "Dhanmondi".'],
                        'type' => ['type' => 'string', 'description' => 'Structure type, e.g. unipole, multipole, led, billboard.'],
                        'max_monthly_rate' => ['type' => 'number', 'description' => 'Only boards priced monthly at or below this many BDT.'],
                        'max_daily_rate' => ['type' => 'number', 'description' => 'Only boards priced daily at or below this many BDT.'],
                        'min_rating' => ['type' => 'number', 'description' => 'Minimum rating out of 5.'],
                        'available_from' => ['type' => 'string', 'description' => 'Start of the wanted period, YYYY-MM-DD. Requires available_to.'],
                        'available_to' => ['type' => 'string', 'description' => 'End of the wanted period, YYYY-MM-DD. Requires available_from.'],
                        'limit' => ['type' => 'integer', 'description' => 'How many boards to return. Defaults to 10.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_my_bookings',
                'description' => "List the signed-in client's own bookings, newest first, with the pipeline stage each one is at and what is still owed. Use this for any question about their campaigns, requests, or what they have paid.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Optional filter, e.g. confirmed, active, pending_admin_review, rejected.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_booking_detail',
                'description' => "Full detail for one of the signed-in client's bookings: campaign details, every payment made, amount still outstanding, and the next action they need to take. Use it after get_my_bookings when the user asks about a specific booking.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'booking_id' => ['type' => 'integer', 'description' => 'The booking id, as returned by get_my_bookings.'],
                    ],
                    'required' => ['booking_id'],
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
            'search_billboards' => $this->searchBillboards($input),
            'get_my_bookings' => $this->myBookings($input, $user),
            'get_booking_detail' => $this->bookingDetail($input, $user),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function searchBillboards(array $input): array
    {
        $from = $this->date($input['available_from'] ?? null);
        $to = $this->date($input['available_to'] ?? null);

        if (($from && ! $to) || ($to && ! $from)) {
            return ['error' => 'available_from and available_to must be given together.'];
        }
        if ($from && $to && $from->gt($to)) {
            return ['error' => 'available_from must not be after available_to.'];
        }

        $limit = min(max((int) ($input['limit'] ?? 10), 1), $this->maxRows);
        $blocked = $from && $to ? $this->blockedBillboardIds($from, $to) : [];

        $query = $this->boardQuery($input, $blocked, withPriceCap: true);
        $total = (clone $query)->count();

        $boards = $query->orderByDesc('rating')->limit($limit)->get()
            ->map(fn (Billboard $b) => $this->boardRow($b))
            ->all();

        $result = [
            'matches_found' => $total,
            'showing' => count($boards),
            'checked_availability_for' => $from && $to
                ? ['from' => $from->toDateString(), 'to' => $to->toDateString()]
                : null,
            'billboards' => $boards,
        ];

        // Nothing inside the budget is not the same as nothing at all. Without
        // this the tool returns an empty list, the model has nothing to work
        // with, and the user is told "no" and asked whether they would like a
        // wider search - when the honest answer is "not under that, but here is
        // the cheapest one that fits everything else". Answering the question
        // the user actually has is the tool's job, not the model's to guess at.
        if ($total === 0 && $this->hasPriceCap($input)) {
            $nearest = $this->boardQuery($input, $blocked, withPriceCap: false)
                ->orderBy(isset($input['max_daily_rate']) ? 'daily_rate' : 'monthly_rate')
                ->limit(3)
                ->get();

            if ($nearest->isNotEmpty()) {
                $result['nothing_within_budget'] = true;
                $result['budget_asked_for_bdt'] = (float) ($input['max_monthly_rate'] ?? $input['max_daily_rate']);
                $result['cheapest_above_budget'] = $nearest->map(fn (Billboard $b) => $this->boardRow($b))->all();
                $result['how_to_answer'] = 'Nothing matched inside that budget. Do not stop at "no" and do not offer to search again - tell the user nothing fits their budget, then show them these, cheapest first, with their prices, so they can decide whether to stretch.';
            }
        }

        return $result;
    }

    /**
     * The filtered board query, optionally without the price ceiling so the
     * same filters can be re-run to find the nearest thing above budget.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, int>  $blocked
     */
    private function boardQuery(array $input, array $blocked, bool $withPriceCap): Builder
    {
        $query = Billboard::query()
            // The same gate as the public listing: unapproved boards are not
            // reachable by URL, so the assistant must not surface them either.
            ->where('listing_status', 'approved');

        // LOWER(col) LIKE ? rather than Postgres ILIKE: the app runs on
        // PostgreSQL but the test suite runs on SQLite, and this is the one
        // case-insensitive form both understand. Values stay bound, so the
        // model's free text never reaches the SQL itself.
        if ($keyword = trim((string) ($input['keyword'] ?? ''))) {
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$this->contains($keyword)])
                ->orWhereRaw('LOWER(address) LIKE ?', [$this->contains($keyword)]));
        }
        if ($area = trim((string) ($input['area'] ?? ''))) {
            $query->whereRaw('LOWER(address) LIKE ?', [$this->contains($area)]);
        }
        if ($type = trim((string) ($input['type'] ?? ''))) {
            $query->whereRaw('LOWER(type) = ?', [mb_strtolower($type)]);
        }
        if (isset($input['min_rating'])) {
            $query->where('rating', '>=', (float) $input['min_rating']);
        }

        // "Under 2 lakh a month" says two things - a ceiling AND that they want
        // a monthly-priced board. Relaxing the budget drops the ceiling only;
        // the pricing mode is still what they asked for.
        if (isset($input['max_monthly_rate'])) {
            $query->where('pricing_mode', 'monthly');
            if ($withPriceCap) {
                $query->where('monthly_rate', '<=', (float) $input['max_monthly_rate']);
            }
        }
        if (isset($input['max_daily_rate'])) {
            $query->where('pricing_mode', 'daily');
            if ($withPriceCap) {
                $query->where('daily_rate', '<=', (float) $input['max_daily_rate']);
            }
        }

        if ($blocked !== []) {
            $query->whereNotIn('id', $blocked);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasPriceCap(array $input): bool
    {
        return isset($input['max_monthly_rate']) || isset($input['max_daily_rate']);
    }

    /**
     * @return array<string, mixed>
     */
    private function boardRow(Billboard $billboard): array
    {
        return [
            'billboard_id' => $billboard->id,
            'title' => $billboard->title,
            'address' => $billboard->address,
            'type' => $billboard->type,
            'size' => $billboard->size,
            'pricing_mode' => $billboard->pricing_mode,
            'daily_rate_bdt' => (float) $billboard->daily_rate,
            'monthly_rate_bdt' => (float) $billboard->monthly_rate,
            'rating' => (float) $billboard->rating,
            'permit_expires' => $billboard->permit_expiry_date?->toDateString(),
        ];
    }

    /**
     * Billboards whose dates already overlap the wanted period. An expired hold
     * releases its dates, so it must not count against availability - that is
     * the same rule the booking wizard applies at hold time.
     *
     * @return array<int, int>
     */
    private function blockedBillboardIds(Carbon $from, Carbon $to): array
    {
        return Booking::query()
            ->whereIn('status', self::OCCUPYING_STATUSES)
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', 'held')
                ->orWhere('expires_at', '>', now()))
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->pluck('billboard_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function myBookings(array $input, User $user): array
    {
        $query = Booking::query()
            ->where('user_id', $user->id)
            ->when(
                trim((string) ($input['status'] ?? '')) !== '',
                fn (Builder $q) => $q->where('status', trim((string) $input['status']))
            );

        // The true count and the returned count are reported separately. With
        // one number the model reads the row cap as the total and tells the
        // user they have exactly as many bookings as fitted in the response.
        $total = (clone $query)->count();

        $bookings = $query
            ->with(['billboard:id,title,address', 'payments'])
            ->orderByDesc('created_at')
            ->limit($this->maxRows)
            ->get();

        return [
            'bookings_found' => $total,
            'showing' => $bookings->count(),
            'bookings' => $bookings->map(fn (Booking $b) => $this->bookingRow($b))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function bookingDetail(array $input, User $user): array
    {
        $booking = Booking::query()
            // Scoped before the id is applied: a booking belonging to someone
            // else is simply not found, never merely hidden by a prompt rule.
            ->where('user_id', $user->id)
            ->where('id', (int) ($input['booking_id'] ?? 0))
            ->with(['billboard:id,title,address,type,size', 'payments', 'proofOfPostings'])
            ->first();

        if (! $booking) {
            return ['error' => 'No booking with that id belongs to you.'];
        }

        $row = $this->bookingRow($booking);
        $row['campaign'] = [
            'brand_name' => $booking->brand_name,
            'ad_category' => $booking->ad_category,
            'description' => $booking->campaign_description,
        ];
        $row['payments'] = $booking->payments
            ->map(fn ($p) => [
                'type' => $p->payment_type,
                'amount_bdt' => (float) $p->amount,
                'status' => $p->status,
                'method' => $p->method,
                'paid_at' => $p->paid_at?->toDateTimeString(),
                'refunded_at' => $p->refunded_at?->toDateTimeString(),
            ])
            ->all();
        $row['proof_of_posting'] = $booking->proofOfPostings
            ->map(fn ($p) => ['status' => $p->status, 'verified_at' => $p->verified_at?->toDateTimeString()])
            ->all();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingRow(Booking $booking): array
    {
        $paid = $booking->payments
            ->where('status', 'paid')
            ->whereNull('refunded_at')
            ->sum(fn ($p) => (float) $p->amount);

        return [
            'booking_id' => $booking->id,
            'billboard_id' => $booking->billboard_id,
            'billboard_title' => $booking->billboard?->title,
            'billboard_address' => $booking->billboard?->address,
            'brand_name' => $booking->brand_name,
            'start_date' => $booking->start_date?->toDateString(),
            'end_date' => $booking->end_date?->toDateString(),
            'status' => $booking->status,
            'status_means' => self::STATUS_MEANINGS[$booking->status] ?? null,
            'total_amount_bdt' => (float) $booking->total_amount,
            'advance_amount_bdt' => (float) $booking->advance_amount,
            'paid_so_far_bdt' => round($paid, 2),
            'outstanding_bdt' => round((float) $booking->total_amount - $paid, 2),
            'final_payment_due_at' => $booking->final_payment_due_at?->toDateTimeString(),
            'hold_expires_at' => $booking->status === 'held' ? $booking->expires_at?->toDateTimeString() : null,
            'rejection_reason' => $booking->rejection_reason,
        ];
    }

    /** A lower-cased LIKE pattern, for the portable case-insensitive match above. */
    private function contains(string $value): string
    {
        return '%'.mb_strtolower($value).'%';
    }

    private function date(mixed $value): ?Carbon
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
