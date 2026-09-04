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
     * Platform money, per billboard per month.
     *
     * Two income streams feed this, and they are NOT the same kind of money:
     *
     *   - booking payments: `gross` is what the client pays, most of which is
     *     owed to the board owner - only `commission` is the platform's cut.
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
        // One row per paid ADVANCE, so each booking is counted once - the
        // balance payment carries the same bookings.total_amount and would
        // double the gross.
        $bookingPayments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('payments.payment_type', 'advance')
            ->where('payments.status', 'paid')
            ->select(
                'billboards.id as billboard_id',
                'billboards.title as billboard_title',
                'payments.created_at as earned_at',
                'bookings.total_amount as gross',
                'payments.commission_amount as commission',
                'payments.owner_payable as owner_payable',
            )
            ->get();

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
            $key = $bucketKey($payment->billboard_id, $payment->billboard_title, $payment->earned_at);
            $buckets[$key]['gross'] += (float) $payment->gross;
            $buckets[$key]['commission'] += (float) $payment->commission;
            $buckets[$key]['owner_payable'] += (float) $payment->owner_payable;
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
