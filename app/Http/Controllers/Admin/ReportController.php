<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use App\Models\Booking;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Booking statuses where BOTH approvals are in - admin has reviewed it and
     * the owner has accepted - so the advance can no longer be rejected and
     * auto-refunded. Anything earlier (pending_admin_review,
     * pending_owner_approval) is money the platform may still have to return.
     */
    private const EARNED_BOOKING_STATUSES = ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'];

    /**
     * Platform money, per billboard per month.
     *
     * Two income streams feed this, and they are NOT the same kind of money:
     *
     *   - booking payments: `gross` is the cash actually collected from the
     *     client, most of which is owed to the board owner - only `commission`
     *     is the platform's cut, pro-rated against that collected cash at the
     *     booking's frozen rate. Money that could still be refunded is left
     *     out until it cannot be (see EARNED_BOOKING_STATUSES).
     *   - board listing fees: the one-time fee an owner pays to list a board
     *     (Owner\ListingPaymentController). There is no owner split at all -
     *     the whole amount is platform income - but only once admin approves
     *     the board, since rejecting it refunds the fee. It gets its own
     *     column rather than being mixed into `commission`, and
     *     `platform_income` adds the two together for the admin dashboard's
     *     headline figure.
     *
     * Rows are bucketed in PHP rather than with SQL date functions so this
     * works on both the pgsql app DB and the sqlite test DB.
     */
    public function revenue(): JsonResponse
    {
        // Booking money is recognised per PAYMENT, as it stops being refundable:
        //
        //   advance - the client pays it to SUBMIT the request. Admin reviews,
        //             then the owner does, and either one rejecting refunds it
        //             in full (Shared\RefundService). So it is not income
        //             while the booking sits in pending_admin_review or
        //             pending_owner_approval - only once it reaches 'confirmed'
        //             (both approvals in) or beyond.
        //   balance - only ever payable after the booking is confirmed, and
        //             there is no rejection left that could send it back, so it
        //             counts the moment it is paid.
        //
        // Neither commission nor owner_payable can be read off the row. Both
        // columns are frozen against the WHOLE booking on the advance row (the
        // balance row carries commission 0), so a client who has only paid the
        // advance would book the platform its full cut of money nobody has
        // collected yet - and if that client never pays the balance, that cut
        // never arrives. Both are pro-rated below against the cash in hand
        // instead, at the booking's own frozen rate.
        $bookingPayments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', self::EARNED_BOOKING_STATUSES);
            })
            ->select(
                'billboards.id as billboard_id',
                'billboards.title as billboard_title',
                DB::raw('COALESCE(payments.paid_at, payments.created_at) as earned_at'),
                'payments.amount as gross',
                'payments.booking_id as booking_id',
                'bookings.total_amount as booking_total',
            )
            ->get();

        // Each booking's commission RATE, taken from the amount frozen onto it
        // at pay time rather than from Setting::get('commission_rate') - the
        // admin can change that setting later, and an old booking must keep the
        // rate it was sold at. Summing every row is safe because only the
        // advance row carries any commission.
        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        // A listing fee is only income once ADMIN HAS APPROVED the board.
        // Paying it just puts the board in the review queue: admin can still
        // reject, and rejecting refunds the fee in full (Admin\ListingRefundService),
        // so a fee sitting on a pending_review board is money the platform may
        // yet have to give back - counting it books revenue that does not exist.
        // Requiring the board to be approved as well as the fee paid also means
        // a refund that somehow failed to run can never leak into the totals.
        //
        // It is earned at approval, not at payment, so the month bucket follows
        // reviewed_at - otherwise approving today would silently rewrite an
        // earlier month. Boards approved before that column existed fall back.
        $listingFees = DB::table('listing_payments')
            ->join('billboards', 'billboards.id', '=', 'listing_payments.billboard_id')
            ->where('listing_payments.status', 'paid')
            ->where('billboards.listing_status', 'approved')
            ->select(
                'billboards.id as billboard_id',
                'billboards.title as billboard_title',
                DB::raw('COALESCE(billboards.reviewed_at, listing_payments.paid_at, listing_payments.created_at) as earned_at'),
                'listing_payments.amount as listing_fees',
            )
            ->get();

        $buckets = [];

        $bucketKey = function ($billboardId, $title, $earnedAt) use (&$buckets): string {
            $month = Carbon::parse($earnedAt)->format('Y-m');
            $key = $billboardId.'|'.$month;

            $buckets[$key] ??= [
                'billboard_id' => (int) $billboardId,
                'billboard_title' => $title,
                'month' => $month,
                'gross' => 0.0,
                'commission' => 0.0,
                'owner_payable' => 0.0,
                'listing_fees' => 0.0,
            ];

            return $key;
        };

        foreach ($bookingPayments as $payment) {
            $collected = (float) $payment->gross;
            $bookingTotal = (float) $payment->booking_total;

            // Commission follows the cash: 10% (or whatever rate the booking
            // was frozen at) of what has actually been collected, not of the
            // contract. Advance 3,000 of a 10,000 booking at 10% earns 300 now;
            // the remaining 700 lands with the balance payment, and only if it
            // is ever paid. Whatever is left of the payment is the owner's.
            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;
            $commission = round($collected * $rate, 2);

            $key = $bucketKey($payment->billboard_id, $payment->billboard_title, $payment->earned_at);

            $buckets[$key]['gross'] += $collected;
            $buckets[$key]['commission'] += $commission;
            $buckets[$key]['owner_payable'] += $collected - $commission;
        }

        foreach ($listingFees as $fee) {
            $key = $bucketKey($fee->billboard_id, $fee->billboard_title, $fee->earned_at);
            $buckets[$key]['listing_fees'] += (float) $fee->listing_fees;
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
