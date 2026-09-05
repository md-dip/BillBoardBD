<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Booking statuses where both approvals are in - admin has reviewed the
     * booking and this owner has accepted it - so the advance can no longer be
     * rejected and auto-refunded back to the client.
     *
     * Deliberately this controller's own copy rather than a shared constant:
     * the owner's ledger answers a different question from the admin's (what
     * did MY boards earn ME, with no listing fees and no platform-wide totals),
     * and keeping it self-contained means an admin-side change can never move
     * an owner's numbers by accident.
     */
    private const EARNED_BOOKING_STATUSES = ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'];

    /**
     * Statuses where the client has paid in full, which is the point money can
     * begin its journey to a payout. A 'confirmed' booking is earned revenue
     * but the balance is still outstanding, so it is never payable yet. Mirrors
     * PayoutService::SETTLED_STATUSES, which is what the Payouts page uses.
     */
    private const PAYABLE_BOOKING_STATUSES = ['paid_in_full', 'pending_proof_review', 'active'];

    /**
     * Every payment collected on this owner's boards - what the "Revenue (BDT)"
     * tile on their dashboard is made of - and where each one's earnings have
     * got to since.
     *
     *   advance - the client pays it to submit the request, but admin or this
     *             owner rejecting refunds it in full, so it only counts once
     *             the booking has cleared BOTH approvals.
     *   balance - only payable after that point and never refundable, so it
     *             counts the moment it is paid.
     *
     * Board listing fees are not here on purpose: that is money the owner PAID
     * the platform to list a board, not money a board earned them.
     *
     * Every earned taka sits in exactly one of three buckets, which is what
     * answers "I was paid, so why has my earnings figure not moved?":
     *
     *   paid_out - already disbursed in a payout run.
     *   ready    - client paid in full and admin verified the proof of
     *              installation, so it is waiting on the next payout run.
     *   held     - earned, but not payable yet: either the client still owes
     *              the balance, or the proof of installation has not been
     *              uploaded/verified.
     */
    public function transactions(Request $request): JsonResponse
    {
        $ownerId = $request->user()->id;

        $payments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->leftJoin('users as clients', 'clients.id', '=', 'bookings.user_id')
            ->where('billboards.owner_id', $ownerId)
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', self::EARNED_BOOKING_STATUSES);
            })
            ->select(
                'payments.id as payment_id',
                'payments.payment_type',
                'payments.amount',
                'payments.method',
                DB::raw('COALESCE(payments.paid_at, payments.created_at) as earned_at'),
                'bookings.id as booking_id',
                'bookings.status as booking_status',
                'bookings.total_amount as booking_total',
                'bookings.brand_name',
                'billboards.id as billboard_id',
                'billboards.title as billboard_title',
                'clients.name as client_name',
            )
            ->get();

        // The commission RATE each booking was sold at, recovered from the
        // amount frozen onto it at pay time - never from today's setting, which
        // the admin can change without touching an old booking. Only the
        // advance row carries any, so summing the booking's rows is safe.
        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        $bookingIds = $payments->pluck('booking_id')->unique();

        // Which bookings have already been paid out, and in which run. A payout
        // stamps payout_id on the ADVANCE row only, and that row's owner_payable
        // covers the whole booking (see PayoutService), so the booking's advance
        // row is what says whether its money has been disbursed - the balance
        // row of a paid-out booking never carries a payout_id of its own.
        $payoutByBooking = DB::table('payments')
            ->join('payouts', 'payouts.id', '=', 'payments.payout_id')
            ->where('payments.payment_type', 'advance')
            ->whereIn('payments.booking_id', $bookingIds)
            ->select(
                'payments.booking_id',
                'payouts.id as payout_id',
                'payouts.paid_at as payout_paid_at',
                'payouts.reference as payout_reference',
            )
            ->get()
            ->keyBy('booking_id');

        // Admin has accepted the proof of installation - the gate money must
        // pass before it can be paid out at all.
        $proofVerified = DB::table('proof_of_postings')
            ->whereIn('booking_id', $bookingIds)
            ->where('status', 'verified')
            ->pluck('booking_id')
            ->flip();

        $transactions = $payments
            ->map(function ($payment) use ($frozenCommission, $payoutByBooking, $proofVerified) {
                $collected = (float) $payment->amount;
                $bookingTotal = (float) $payment->booking_total;

                // The cut follows the cash: the platform's share of what has
                // actually been collected, not of the whole contract.
                $rate = $bookingTotal > 0
                    ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                    : 0.0;
                $platformCut = round($collected * $rate, 2);

                $payout = $payoutByBooking[$payment->booking_id] ?? null;

                if ($payout) {
                    $status = 'paid_out';
                } elseif (in_array($payment->booking_status, self::PAYABLE_BOOKING_STATUSES, true)
                    && $proofVerified->has($payment->booking_id)) {
                    $status = 'ready';
                } else {
                    $status = 'held';
                }

                return [
                    'id' => 'payment-'.$payment->payment_id,
                    'type' => $payment->payment_type === 'balance' ? 'booking_balance' : 'booking_advance',
                    'earned_at' => (string) $payment->earned_at,
                    'month' => Carbon::parse($payment->earned_at)->format('Y-m'),
                    'billboard_id' => (int) $payment->billboard_id,
                    'billboard_title' => $payment->billboard_title,
                    'booking_id' => (int) $payment->booking_id,
                    'brand_name' => $payment->brand_name,
                    'client_name' => $payment->client_name,
                    'method' => $payment->method,
                    'amount' => round($collected, 2),
                    'commission_rate' => round($rate * 100, 2),
                    'platform_cut' => $platformCut,
                    'owner_earning' => round($collected - $platformCut, 2),
                    'payout_status' => $status,
                    'payout_id' => $payout?->payout_id,
                    'paid_out_at' => $payout?->payout_paid_at,
                    'payout_reference' => $payout?->payout_reference,
                ];
            })
            ->sortByDesc('earned_at')
            ->values();

        $inBucket = fn (string $bucket) => round(
            (float) $transactions->where('payout_status', $bucket)->sum('owner_earning'), 2
        );

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'totals' => [
                    'count' => $transactions->count(),
                    // Matches the dashboard's Revenue (BDT) tile exactly - both
                    // count the same collected payments.
                    'collected' => round((float) $transactions->sum('amount'), 2),
                    'platform_cut' => round((float) $transactions->sum('platform_cut'), 2),
                    'earnings' => round((float) $transactions->sum('owner_earning'), 2),
                    // The three buckets always add back up to `earnings`, since
                    // every row lands in exactly one of them.
                    'paid_out' => $inBucket('paid_out'),
                    'ready_for_payout' => $inBucket('ready'),
                    'held' => $inBucket('held'),
                ],
            ],
            'message' => null,
        ]);
    }
}
