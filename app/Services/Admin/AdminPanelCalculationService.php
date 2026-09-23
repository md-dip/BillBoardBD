<?php

namespace App\Services\Admin;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\Shared\LedgerTransactionType;
use App\Services\Shared\RevenueRecognitionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminPanelCalculationService
{

    private const OWNER_PAYABLE_BOOKING_STATUSES = ['paid_in_full', 'pending_proof_review', 'active'];

    private const PENDING_BOOKING_STATUS = 'pending_admin_review';

    private const PERMIT_EXPIRY_WARNING_DAYS = 90;


    // TOTAL REVENUE
    //
    /**
     * Every payment that has actually entered the platform
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchRevenueLedger(): Collection
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
                'type' => LedgerTransactionType::forBookingPayment($payment->payment_type),
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
                'type' => LedgerTransactionType::LISTING_FEE,
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
                'commission_rate' => 100.0,
                'platform_cut' => $amount,
                'owner_payable' => 0.0,
            ]);
        }

        return $ledger;
    }

    /**
     * Platform money, per billboard per month - the numbers behind the
     * admin dashboard's "Total revenue" tile, the Reports page, and the
     * revenue-by-month chart.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function revenueSummary(): array
    {
        $ledger = $this->fetchRevenueLedger();

        $buckets = [];
// monthly revenue per billboard, with commission and listing fees broken out
        foreach ($ledger as $entry) {
            $key = $entry['billboard_id'].'|'.$entry['month'];

            $buckets[$key] ??= [
                'billboard_id' => $entry['billboard_id'],
                'billboard_title' => $entry['billboard_title'],
                'month' => $entry['month'],
                'total_revenue' => 0.0,
                'commission' => 0.0,
                'owner_payable' => 0.0,
                'listing_fees' => 0.0,
            ];

            $buckets[$key]['total_revenue'] += $entry['amount'];
            $buckets[$key]['owner_payable'] += $entry['owner_payable'];

            
            if ($entry['type'] === LedgerTransactionType::LISTING_FEE) {
                $buckets[$key]['listing_fees'] += $entry['platform_cut'];
            } else {
                $buckets[$key]['commission'] += $entry['platform_cut'];
            }
        }

        $rows = collect($buckets)
            ->sortBy([['month', 'asc'], ['billboard_id', 'asc']])
            ->values()
            ->map(fn (array $row) => array_merge($row, [
                'total_revenue' => round($row['total_revenue'], 2),
                'commission' => round($row['commission'], 2),
                'owner_payable' => round($row['owner_payable'], 2),
                'listing_fees' => round($row['listing_fees'], 2),
            ]));

        $commission = 0.0;
        $ownerPayable = 0.0;
        $listingFees = 0.0;

        foreach ($rows as $row) {
            $commission = $commission + $row['commission'];
            $ownerPayable = $ownerPayable + $row['owner_payable'];
            $listingFees = $listingFees + $row['listing_fees'];
        }

        $totalRevenue = $commission + $listingFees + $ownerPayable;

        $totals = [
            'total_revenue' => round($totalRevenue, 2),
            'commission' => round($commission, 2),
            'owner_payable' => round($ownerPayable, 2),
            'listing_fees' => round($listingFees, 2),
            'from_booking_payments' => round($totalRevenue - $listingFees, 2),
            'platform_commission' => round($commission + $listingFees, 2),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    // PLATFORM COMMISSION

    /**
     * Every single transaction the platform has earned a cut - reuses the
     * same ledger as revenueSummary() above (fetchRevenueLedger()), since
     * both blocks were reading the exact same set of payments and listing
     * fees, they just totaled them up differently.
     *
     * @return array{transactions: Collection<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function transactionsList(): array
    {
        $transactions = $this->fetchRevenueLedger()->sortByDesc('earned_at')->values();


        $count = 0;
        $bookingGross = 0.0;
        $bookingCut = 0.0;
        $listingCut = 0.0;
        $ownerPayable = 0.0;

        foreach ($transactions as $transaction) {
            $count = $count + 1;
            $ownerPayable = $ownerPayable + $transaction['owner_payable'];

            if ($transaction['type'] === LedgerTransactionType::LISTING_FEE) {
                $listingCut = $listingCut + $transaction['platform_cut'];
            } else {
                $bookingGross = $bookingGross + $transaction['amount'];
                $bookingCut = $bookingCut + $transaction['platform_cut'];
            }
        }


        $platformCommission = $bookingCut + $listingCut;

        $totalRevenue = $platformCommission + $ownerPayable;

        $totals = [
            'count' => $count,
            'total_revenue' => round($totalRevenue, 2),
            'commission' => round($bookingCut, 2),
            'listing_fees' => round($listingCut, 2),
            'from_booking_payments' => round($bookingGross, 2),
            'platform_commission' => round($platformCommission, 2),
            'owner_payable' => round($ownerPayable, 2),
        ];

        return ['transactions' => $transactions, 'totals' => $totals];
    }


    public function platformCommission(): float
    {
        $bookingCut = 0.0;
        $listingCut = 0.0;

        foreach ($this->fetchRevenueLedger() as $entry) {
            if ($entry['type'] === LedgerTransactionType::LISTING_FEE) {
                $listingCut = $listingCut + $entry['platform_cut'];
            } else {
                $bookingCut = $bookingCut + $entry['platform_cut'];
            }
        }

        $platformCommission = $bookingCut + $listingCut;

        return round($platformCommission, 2);
    }


    //  PAYABLE TO OWNER


    public function settledPaymentsQueryForOwner(int $ownerId)
    {
        return Payment::query()
            ->where('payment_type', 'advance')                          
            ->where('status', 'paid')                                   
            ->whereNull('payout_id')                                    
            ->whereHas('booking', function ($booking) use ($ownerId) {
                $booking->whereIn('status', self::OWNER_PAYABLE_BOOKING_STATUSES)                 
                    ->whereHas('billboard', fn ($billboard) => $billboard->where('owner_id', $ownerId)) 
                    ->whereHas('proofOfPostings', fn ($proof) => $proof->where('status', 'verified')); 
            });
    }

    public function payableToOwner(User $owner): float
    {
        $payments = $this->settledPaymentsQueryForOwner($owner->id)->get();
        $payable = 0.0;
        foreach ($payments as $payment) {
            $payable = $payable + (float) $payment->owner_payable;
        }

        return round($payable, 2);
    }

    /**
     * The same figure for every owner who currently has something owed to
     * them - what backs the Admin Payouts page.
     *
     * @return Collection<int, array{owner: User, amount: float}>
     */
    public function payableToAllOwners(): Collection
    {
        return User::query()
            ->where('role', 'owner')
            ->get()
            ->map(fn (User $owner) => ['owner' => $owner, 'amount' => $this->payableToOwner($owner)])
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->values();
    }

   
    //  PENDING BOOKINGS
    /** Bookings currently sitting in admin's review queue. */
    public function pendingBookingsCount(): int
    {
        return Booking::query()->where('status', self::PENDING_BOOKING_STATUS)->count();
    }

   
    // 5. PERMITS EXPIRING

    public function permitsExpiringCount(int $withinDays = self::PERMIT_EXPIRY_WARNING_DAYS): int
    {
        return Billboard::query()
            ->whereNotNull('permit_expiry_date')
            ->where('permit_expiry_date', '<', Carbon::today()->addDays($withinDays))
            ->count();
    }


    /**
     * @return array{
     *     total_revenue: float,
     *     platform_commission: float,
     *     payable_to_owners: float,
     *     pending_bookings: int,
     *     permits_expiring: int,
     * }
     */
    public function dashboardSummary(): array
    {
        $payableToOwners = 0.0;
        foreach ($this->payableToAllOwners() as $row) {
            $payableToOwners = $payableToOwners + $row['amount'];
        }

        return [
            'total_revenue' => $this->revenueSummary()['totals']['total_revenue'],
            'platform_commission' => $this->transactionsList()['totals']['platform_commission'],
            'payable_to_owners' => round($payableToOwners, 2),
            'pending_bookings' => $this->pendingBookingsCount(),
            'permits_expiring' => $this->permitsExpiringCount(),
        ];
    }
}
