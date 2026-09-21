<?php

namespace App\Services\Owner;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OwnerPanelCalculationService
{
// filter is the amount is still refundable or not
    private const EARNED_BOOKING_STATUSES = ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'];

// does the owner will get payout for this booking or not 
    private const PAYABLE_BOOKING_STATUSES = ['paid_in_full', 'pending_proof_review', 'active'];

// awaiting admin verification means the owner has uploaded proof, but admin has not accepted it yet
    private const AWAITING_ADMIN_STATUS = 'pending_proof_review';

   
    // 1. REVENUE COLLECTED
    
    /** Every taka actually collected on this owner's boards. */
    public function revenueCollected(int $ownerId): float
    {
        return round((float) $this->fetchRevenueCollectedLedger($ownerId)->sum('amount'), 2);
    }

    /**
     * Fetches the revenue collected ledger for a given owner.
     * 
     * @return Collection<int, array{amount: float}>
     */
    private function fetchRevenueCollectedLedger(int $ownerId): Collection
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
            ->select('payments.id as payment_id', 'payments.amount')
            ->get();

        // Nothing to work out - a payment's own amount already IS revenue collected.
        return $payments->map(fn ($payment) => ['amount' => round((float) $payment->amount, 2)]);
    }

    // 2. PLATFORM COMMISSION

    /** The platform's cut of everything collected on this owner's boards. */
    public function platformCommission(int $ownerId): float
    {
        return round((float) $this->fetchPlatformCommissionLedger($ownerId)->sum('platform_cut'), 2);
    }

    /**
     * Working out platform_cut still needs the collected amount
     * @return Collection<int, array{platform_cut: float}>
     */
    private function fetchPlatformCommissionLedger(int $ownerId): Collection
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
                'payments.id as payment_id',
                'payments.amount',
                'bookings.id as booking_id',
                'bookings.total_amount as booking_total',
            )
            ->get();

        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        return $payments->map(function ($payment) use ($frozenCommission) {
            $collected = (float) $payment->amount;
            $bookingTotal = (float) $payment->booking_total;

            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;

            // The platform's cut: how much was collected, times the rate this booking was sold at.
            return ['platform_cut' => round($collected * $rate, 2)];
        });
    }

    // 3. YOUR EARNINGS (ALL TIME)
  
    /** Revenue collected minus platform commission - what the owner actually keeps. */
    public function earnings(int $ownerId): float
    {
        return round((float) $this->fetchEarningsLedger($ownerId)->sum('owner_earning'), 2);
    }

    /**
     * amount and platform_cut needed  intermediate values to work
     * @return Collection<int, array{owner_earning: float}>
     */
    private function fetchEarningsLedger(int $ownerId): Collection
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
                'payments.id as payment_id',
                'payments.amount',
                'bookings.id as booking_id',
                'bookings.total_amount as booking_total',
            )
            ->get();

        $frozenCommission = DB::table('payments')
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(commission_amount) as commission')
            ->pluck('commission', 'booking_id');

        return $payments->map(function ($payment) use ($frozenCommission) {
            $collected = (float) $payment->amount;
            $bookingTotal = (float) $payment->booking_total;

            $rate = $bookingTotal > 0
                ? (float) ($frozenCommission[$payment->booking_id] ?? 0) / $bookingTotal
                : 0.0;
            $platformCut = round($collected * $rate, 2);

            // What's left for the owner once the platform's cut comes out.
            return ['owner_earning' => round($collected - $platformCut, 2)];
        });
    }


    // 4. PAID OUT TO YOU
    public function paidOut(int $ownerId): float
    {
        return round((float) $this->fetchPaidOutLedger($ownerId)->sum('amount'), 2);
    }

    /**
     * @return Collection<int, array{amount: float}>
     */
    private function fetchPaidOutLedger(int $ownerId): Collection
    {
        // A row existing here means admin has already sent this owner money -
        // nothing to calculate, just add up what has actually been paid.
        return DB::table('payouts')
            ->where('owner_id', $ownerId)
            ->select('amount')
            ->get()
            ->map(fn ($payout) => ['amount' => round((float) $payout->amount, 2)]);
    }

    // ========================================================================
    // 5. READY FOR PAYOUT
    // ========================================================================

    /** Earnings settled and proof-verified, waiting on the next payout run. */
    public function readyForPayout(int $ownerId): float
    {
        return round(
            (float) $this->fetchReadyForPayoutLedger($ownerId)->where('payout_status', 'ready')->sum('owner_earning'),
            2
        );
    }

    /** Its own independent copy of the same classification query - see paidOut() above.
     * @return Collection<int, array{owner_earning: float, payout_status: string}> */
    private function fetchReadyForPayoutLedger(int $ownerId): Collection
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
                'payments.id as payment_id',
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

        return $payments->map(function ($payment) use ($frozenCommission, $payoutByBooking, $proofVerified) {
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
                // Admin accepted the proof: payable on the next run.
                $status = 'ready';
            } elseif ($payment->booking_status === self::AWAITING_ADMIN_STATUS) {
                $status = 'awaiting_verification';
            } else {
                $status = 'in_progress';
            }

            return [
                'owner_earning' => round($collected - $platformCut, 2),
                'payout_status' => $status,
            ];
        });
    }

    // 6. AWAITING VERIFICATION
   
    public function awaitingVerification(int $ownerId): float
    {
        return round(
            (float) $this->fetchAwaitingVerificationLedger($ownerId)->where('payout_status', 'awaiting_verification')->sum('owner_earning'),
            2
        );
    }

    /**  above.
     * @return Collection<int, array{owner_earning: float, payout_status: string}> */
    private function fetchAwaitingVerificationLedger(int $ownerId): Collection
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
                'payments.id as payment_id',
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

        return $payments->map(function ($payment) use ($frozenCommission, $payoutByBooking, $proofVerified) {
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
                // Owner has uploaded the proof, admin has not looked yet.
                $status = 'awaiting_verification';
            } else {
                $status = 'in_progress';
            }

            return [
                'owner_earning' => round($collected - $platformCut, 2),
                'payout_status' => $status,
            ];
        });
    }

    
    // 7. IN PROGRESS
    
    public function inProgress(int $ownerId): float
    {
        return round(
            (float) $this->fetchInProgressLedger($ownerId)->where('payout_status', 'in_progress')->sum('owner_earning'),
            2
        );
    }

    /** Its own independent copy of the same classification query - see paidOut() above.
     * @return Collection<int, array{owner_earning: float, payout_status: string}> */
    private function fetchInProgressLedger(int $ownerId): Collection
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
                'payments.id as payment_id',
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

        return $payments->map(function ($payment) use ($frozenCommission, $payoutByBooking, $proofVerified) {
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
                // Balance still owed, or no proof uploaded yet.
                $status = 'in_progress';
            }

            return [
                'owner_earning' => round($collected - $platformCut, 2),
                'payout_status' => $status,
            ];
        });
    }

  
    // together: everything the Transactions page needs
  
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
     * The full row-by-row transaction list 
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
    }
}
