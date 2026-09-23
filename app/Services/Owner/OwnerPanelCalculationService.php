<?php

namespace App\Services\Owner;

use App\Services\Shared\LedgerTransactionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OwnerPanelCalculationService
{
    private const EARNED_BOOKING_STATUSES = ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'];

    private const PAYABLE_BOOKING_STATUSES = ['paid_in_full', 'pending_proof_review', 'active'];

    private const AWAITING_ADMIN_STATUS = 'pending_proof_review';

    //  REVENUE COLLECTED
    public function revenueCollected(int $ownerId): float
    {
        $payments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('billboards.owner_id', $ownerId)
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', self::EARNED_BOOKING_STATUSES);
            })
            ->select('payments.amount')
            ->get();

        $collected = 0.0;
        foreach ($payments as $payment) {
            $collected = $collected + (float) $payment->amount;
        }

        return round($collected, 2);
    }

    //  PLATFORM COMMISSION

    public function platformCommission(int $ownerId): float
    {
        $payments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('billboards.owner_id', $ownerId)
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', self::EARNED_BOOKING_STATUSES);
            })
            ->select('payments.amount', 'bookings.id as booking_id', 'bookings.total_amount as booking_total')
            ->get();

        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        $bookingCut = 0.0;
        foreach ($payments as $payment) {
            $collected = (float) $payment->amount;
            $bookingTotal = (float) $payment->booking_total;

            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;

            $bookingCut = $bookingCut + round($collected * $rate, 2);
        }


        $platformCommission = $bookingCut;

        return round($platformCommission, 2);
    }

    // YOUR EARNINGS 

    public function earnings(int $ownerId): float
    {
        return round($this->revenueCollected($ownerId) - $this->platformCommission($ownerId), 2);
    }

    //  PAID OUT  

    public function paidOut(int $ownerId): float
    {
        $payouts = DB::table('payouts')
            ->where('owner_id', $ownerId)
            ->select('amount')
            ->get();

        $paidOut = 0.0;
        foreach ($payouts as $payout) {
            $paidOut = $paidOut + (float) $payout->amount;
        }

        return round($paidOut, 2);
    }

    // READY FOR PAYOUT

    public function readyForPayout(int $ownerId): float
    {
        $payments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('billboards.owner_id', $ownerId)
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', self::EARNED_BOOKING_STATUSES);
            })
            ->select(
                'payments.amount',
                'bookings.id as booking_id',
                'bookings.status as booking_status',
                'bookings.total_amount as booking_total',
            )
            ->get();

        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        $bookingIds = $payments->pluck('booking_id')->unique();

        $payoutByBooking = DB::table('payments')
            ->join('payouts', 'payouts.id', '=', 'payments.payout_id')
            ->where('payments.payment_type', 'advance')
            ->whereIn('payments.booking_id', $bookingIds)
            ->pluck('payouts.id', 'payments.booking_id');

        $proofVerified = DB::table('proof_of_postings')
            ->whereIn('booking_id', $bookingIds)
            ->where('status', 'verified')
            ->pluck('booking_id')
            ->flip();

        $ready = 0.0;
        foreach ($payments as $payment) {
            $collected = (float) $payment->amount;
            $bookingTotal = (float) $payment->booking_total;

            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;
            $platformCut = round($collected * $rate, 2);

            if ($payoutByBooking->has($payment->booking_id)) {
                $status = 'paid_out';
            } elseif (in_array($payment->booking_status, self::PAYABLE_BOOKING_STATUSES, true)
                && $proofVerified->has($payment->booking_id)) {
                $status = 'ready';
            } elseif ($payment->booking_status === self::AWAITING_ADMIN_STATUS) {
                $status = 'awaiting_verification';
            } else {
                $status = 'in_progress';
            }

            if ($status === 'ready') {
                $ready = $ready + round($collected - $platformCut, 2);
            }
        }

        return round($ready, 2);
    }

    //  AWAITING 

    public function awaitingVerification(int $ownerId): float
    {
        $payments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('billboards.owner_id', $ownerId)
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', self::EARNED_BOOKING_STATUSES);
            })
            ->select(
                'payments.amount',
                'bookings.id as booking_id',
                'bookings.status as booking_status',
                'bookings.total_amount as booking_total',
            )
            ->get();

        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        $bookingIds = $payments->pluck('booking_id')->unique();

        $payoutByBooking = DB::table('payments')
            ->join('payouts', 'payouts.id', '=', 'payments.payout_id')
            ->where('payments.payment_type', 'advance')
            ->whereIn('payments.booking_id', $bookingIds)
            ->pluck('payouts.id', 'payments.booking_id');

        $proofVerified = DB::table('proof_of_postings')
            ->whereIn('booking_id', $bookingIds)
            ->where('status', 'verified')
            ->pluck('booking_id')
            ->flip();

        $awaiting = 0.0;
        foreach ($payments as $payment) {
            $collected = (float) $payment->amount;
            $bookingTotal = (float) $payment->booking_total;

            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;
            $platformCut = round($collected * $rate, 2);

            if ($payoutByBooking->has($payment->booking_id)) {
                $status = 'paid_out';
            } elseif (in_array($payment->booking_status, self::PAYABLE_BOOKING_STATUSES, true)
                && $proofVerified->has($payment->booking_id)) {
                $status = 'ready';
            } elseif ($payment->booking_status === self::AWAITING_ADMIN_STATUS) {
                $status = 'awaiting_verification';
            } else {
                $status = 'in_progress';
            }

            if ($status === 'awaiting_verification') {
                $awaiting = $awaiting + round($collected - $platformCut, 2);
            }
        }

        return round($awaiting, 2);
    }

    //  IN PROGRESS

    public function inProgress(int $ownerId): float
    {
        $payments = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('billboards.owner_id', $ownerId)
            ->where('payments.status', 'paid')
            ->where(function ($query) {
                $query->where('payments.payment_type', 'balance')
                    ->orWhereIn('bookings.status', self::EARNED_BOOKING_STATUSES);
            })
            ->select(
                'payments.amount',
                'bookings.id as booking_id',
                'bookings.status as booking_status',
                'bookings.total_amount as booking_total',
            )
            ->get();

        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        $bookingIds = $payments->pluck('booking_id')->unique();

        $payoutByBooking = DB::table('payments')
            ->join('payouts', 'payouts.id', '=', 'payments.payout_id')
            ->where('payments.payment_type', 'advance')
            ->whereIn('payments.booking_id', $bookingIds)
            ->pluck('payouts.id', 'payments.booking_id');

        $proofVerified = DB::table('proof_of_postings')
            ->whereIn('booking_id', $bookingIds)
            ->where('status', 'verified')
            ->pluck('booking_id')
            ->flip();

        $inProgress = 0.0;
        foreach ($payments as $payment) {
            $collected = (float) $payment->amount;
            $bookingTotal = (float) $payment->booking_total;

            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;
            $platformCut = round($collected * $rate, 2);

            if ($payoutByBooking->has($payment->booking_id)) {
                $status = 'paid_out';
            } elseif (in_array($payment->booking_status, self::PAYABLE_BOOKING_STATUSES, true)
                && $proofVerified->has($payment->booking_id)) {
                $status = 'ready';
            } elseif ($payment->booking_status === self::AWAITING_ADMIN_STATUS) {
                $status = 'awaiting_verification';
            } else {
                $status = 'in_progress';
            }

            if ($status === 'in_progress') {
                $inProgress = $inProgress + round($collected - $platformCut, 2);
            }
        }

        return round($inProgress, 2);
    }

    //  FULL TRANSACTION LIST + TOTALS (calls blocks 1-7 above, one by one)

    /**
     * @return array{transactions: Collection<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function transactionsSummary(int $ownerId): array
    {
        $transactions = $this->fetchFullTransactionLedger($ownerId);

        return [
            'transactions' => $transactions,
            'totals' => [
                'count' => $transactions->count(),
                'collected' => $this->revenueCollected($ownerId),
                'platform_cut' => $this->platformCommission($ownerId),
                'earnings' => $this->earnings($ownerId),
                'paid_out' => $this->paidOut($ownerId),
                'ready_for_payout' => $this->readyForPayout($ownerId),
                'awaiting_verification' => $this->awaitingVerification($ownerId),
                'in_progress' => $this->inProgress($ownerId),
            ],
        ];
    }

    /**
     * The full row-by-row transaction list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchFullTransactionLedger(int $ownerId): Collection
    {
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

        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        $bookingIds = $payments->pluck('booking_id')->unique();

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

        $proofVerified = DB::table('proof_of_postings')
            ->whereIn('booking_id', $bookingIds)
            ->where('status', 'verified')
            ->pluck('booking_id')
            ->flip();

        return $payments
            ->map(function ($payment) use ($frozenCommission, $payoutByBooking, $proofVerified) {
                $collected = (float) $payment->amount;
                $bookingTotal = (float) $payment->booking_total;

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
                } elseif ($payment->booking_status === self::AWAITING_ADMIN_STATUS) {
                    $status = 'awaiting_verification';
                } else {
                    $status = 'in_progress';
                }

                return [
                    'id' => 'payment-'.$payment->payment_id,
                    'type' => LedgerTransactionType::forBookingPayment($payment->payment_type),
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
    }
}
