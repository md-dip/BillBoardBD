<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use App\Models\Booking;
use App\Services\Shared\RevenueRecognitionService;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Platform money, per billboard per month - the numbers behind the admin
     * dashboard's KPI tiles and its revenue-by-month chart.
     *
     * Aggregated from ledger(), the same transaction list the drill-down pages
     * render, so a tile and the list behind it can never disagree.
     */
    public function revenue(): JsonResponse
    {
        $buckets = [];

        foreach ($this->ledger() as $entry) {
            $key = $entry['billboard_id'].'|'.$entry['month'];

            $buckets[$key] ??= [
                'billboard_id' => $entry['billboard_id'],
                'billboard_title' => $entry['billboard_title'],
                'month' => $entry['month'],
                'gross' => 0.0,
                'commission' => 0.0,
                'owner_payable' => 0.0,
                'listing_fees' => 0.0,
            ];

            $buckets[$key]['gross'] += $entry['amount'];
            $buckets[$key]['owner_payable'] += $entry['owner_payable'];

            // A listing fee is 100% platform money, so it is reported in its own
            // column rather than mixed into booking commission - the two are
            // added back together as platform_income below.
            if ($entry['type'] === 'listing_fee') {
                $buckets[$key]['listing_fees'] += $entry['platform_cut'];
            } else {
                $buckets[$key]['commission'] += $entry['platform_cut'];
            }
        }

        $rows = collect($buckets)
            ->sortBy([['month', 'asc'], ['billboard_id', 'asc']])
            ->values()
            ->map(fn (array $row) => array_merge($row, [
                'gross' => round($row['gross'], 2),
                'commission' => round($row['commission'], 2),
                'owner_payable' => round($row['owner_payable'], 2),
                'listing_fees' => round($row['listing_fees'], 2),
            ]));

        $totals = [
            'gross' => round((float) $rows->sum('gross'), 2),
            'commission' => round((float) $rows->sum('commission'), 2),
            'owner_payable' => round((float) $rows->sum('owner_payable'), 2),
            'listing_fees' => round((float) $rows->sum('listing_fees'), 2),
        ];

        // What the platform actually keeps: booking commission + listing fees.
        $totals['platform_income'] = round($totals['commission'] + $totals['listing_fees'], 2);

        return response()->json([
            'success' => true,
            'data' => ['rows' => $rows, 'totals' => $totals],
            'message' => null,
        ]);
    }

    /**
     * Every single transaction behind those tiles, newest first - what the
     * admin gets after clicking "Total revenue" or "Platform commission".
     *
     * One row per payment that has actually entered the platform: a booking
     * advance, a booking balance, or a board listing fee. Each carries the
     * platform's cut and the rate it was taken at, so the commission view can
     * show WHY a row contributed what it did - 10% (or whatever that booking
     * was sold at) of a booking payment, or a listing fee kept whole.
     */
    public function transactions(): JsonResponse
    {
        $transactions = $this->ledger()->sortByDesc('earned_at')->values();

        $bookingCut = round((float) $transactions->where('type', '!=', 'listing_fee')->sum('platform_cut'), 2);
        $listingCut = round((float) $transactions->where('type', 'listing_fee')->sum('platform_cut'), 2);

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'totals' => [
                    'count' => $transactions->count(),
                    'gross' => round((float) $transactions->sum('amount'), 2),
                    'commission' => $bookingCut,
                    'listing_fees' => $listingCut,
                    'platform_income' => round($bookingCut + $listingCut, 2),
                    'owner_payable' => round((float) $transactions->sum('owner_payable'), 2),
                ],
            ],
            'message' => null,
        ]);
    }

    /**
     * Every payment the platform has actually earned, normalised into one
     * shape. This is the single definition of "money that entered the system",
     * and both revenue() and transactions() are built from it.
     *
     * What is in, and what is deliberately not:
     *
     *   booking advance - the client pays it to submit the request, and admin
     *                     OR the owner rejecting refunds it in full, so it only
     *                     counts once the booking clears BOTH approvals (see
     *                     Shared\RevenueRecognitionService).
     *   booking balance - only payable after that point, with no rejection
     *                     left, so it counts the moment it is paid.
     *   listing fee     - only once admin approves the board; rejecting the
     *                     board refunds the owner.
     *
     * The platform's cut is pro-rated against the cash in hand at the rate
     * frozen onto the booking, never re-derived from today's setting, and a
     * listing fee is kept whole because there is no owner split on it.
     *
     * Month bucketing and the rate maths happen in PHP rather than in SQL date
     * functions, so this runs on both the pgsql app DB and the sqlite test DB.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ledger(): Collection
    {
        $bookingPayments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->leftJoin('users as clients', 'clients.id', '=', 'bookings.user_id')
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', RevenueRecognitionService::EARNED_BOOKING_STATUSES);
            })
            ->select(
                'payments.id as payment_id',
                'payments.payment_type',
                'payments.amount',
                'payments.method',
                'payments.transaction_ref',
                DB::raw('COALESCE(payments.paid_at, payments.created_at) as earned_at'),
                'bookings.id as booking_id',
                'bookings.total_amount as booking_total',
                'bookings.brand_name',
                'billboards.id as billboard_id',
                'billboards.title as billboard_title',
                'clients.name as payer_name',
            )
            ->get();

        // Each booking's frozen commission, used only to recover the RATE it was
        // sold at - the admin can change commission_rate later and an old
        // booking must keep its own. Only the advance row carries any, so
        // summing every row of the booking is safe.
        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        $listingFees = DB::table('listing_payments')
            ->join('billboards', 'billboards.id', '=', 'listing_payments.billboard_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'listing_payments.owner_id')
            ->where('listing_payments.status', 'paid')
            ->where('billboards.listing_status', 'approved')
            ->select(
                'listing_payments.id as listing_payment_id',
                'listing_payments.amount',
                'listing_payments.method',
                'listing_payments.transaction_ref',
                // Earned at approval, not at payment, so approving today never
                // rewrites a month the admin already read. Boards approved
                // before reviewed_at existed fall back to the payment date.
                DB::raw('COALESCE(billboards.reviewed_at, listing_payments.paid_at, listing_payments.created_at) as earned_at'),
                'billboards.id as billboard_id',
                'billboards.title as billboard_title',
                'owners.name as payer_name',
            )
            ->get();

        $ledger = collect();

        foreach ($bookingPayments as $payment) {
            $collected = (float) $payment->amount;
            $bookingTotal = (float) $payment->booking_total;

            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;
            $cut = round($collected * $rate, 2);

            $ledger->push([
                'id' => 'payment-'.$payment->payment_id,
                'type' => $payment->payment_type === 'balance' ? 'booking_balance' : 'booking_advance',
                'earned_at' => (string) $payment->earned_at,
                'month' => Carbon::parse($payment->earned_at)->format('Y-m'),
                'billboard_id' => (int) $payment->billboard_id,
                'billboard_title' => $payment->billboard_title,
                'booking_id' => (int) $payment->booking_id,
                'brand_name' => $payment->brand_name,
                'payer_name' => $payment->payer_name,
                'payer_role' => 'client',
                'method' => $payment->method,
                'transaction_ref' => $payment->transaction_ref,
                'amount' => round($collected, 2),
                'commission_rate' => round($rate * 100, 2),
                'platform_cut' => $cut,
                'owner_payable' => round($collected - $cut, 2),
            ]);
        }

        foreach ($listingFees as $fee) {
            $amount = round((float) $fee->amount, 2);

            $ledger->push([
                'id' => 'listing-'.$fee->listing_payment_id,
                'type' => 'listing_fee',
                'earned_at' => (string) $fee->earned_at,
                'month' => Carbon::parse($fee->earned_at)->format('Y-m'),
                'billboard_id' => (int) $fee->billboard_id,
                'billboard_title' => $fee->billboard_title,
                'booking_id' => null,
                'brand_name' => null,
                'payer_name' => $fee->payer_name,
                'payer_role' => 'owner',
                'method' => $fee->method,
                'transaction_ref' => $fee->transaction_ref,
                'amount' => $amount,
                // No owner split on a listing fee - the platform keeps it all.
                'commission_rate' => 100.0,
                'platform_cut' => $amount,
                'owner_payable' => 0.0,
            ]);
        }

        return $ledger;
    }

    public function occupancy(): JsonResponse
    {
        $bookings = Booking::query()->whereIn('status', ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'])->get();

        $bookedDays = [];

        foreach ($bookings as $booking) {
            $period = CarbonPeriod::create($booking->start_date, $booking->end_date);

            foreach ($period as $date) {
                $month = $date->format('Y-m');
                $bookedDays[$booking->billboard_id][$month] = ($bookedDays[$booking->billboard_id][$month] ?? 0) + 1;
            }
        }

        $billboards = Billboard::query()->whereIn('id', array_keys($bookedDays))->pluck('title', 'id');

        $result = [];

        foreach ($bookedDays as $billboardId => $months) {
            foreach ($months as $month => $days) {
                $daysInMonth = Carbon::createFromFormat('Y-m', $month)->daysInMonth;

                $result[] = [
                    'billboard_id' => $billboardId,
                    'billboard_title' => $billboards[$billboardId] ?? null,
                    'month' => $month,
                    'booked_days' => $days,
                    'days_in_month' => $daysInMonth,
                    'occupancy_rate' => round(($days / $daysInMonth) * 100, 1),
                ];
            }
        }

        usort($result, fn ($a, $b) => $a['month'] <=> $b['month']);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => null,
        ]);
    }
}
