<?php

namespace Database\Seeders;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\ProofOfPosting;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Gives the demo owner account (owner@test.com) real, non-empty data to show
 * on every part of the Owner Dashboard: a handful of billboards it owns, and
 * bookings against them across every stage of the 5-stage pipeline
 * (pending_admin_review / pending_owner_approval / confirmed / paid_in_full /
 * pending_proof_review / active / rejected) — 2-3 bookings per stage that the
 * owner's own "Booking Requests" page actually shows (it hides
 * pending_admin_review, since the owner never sees a request before admin
 * has forwarded it) — with the matching payment + proof-of-posting rows,
 * computed the same way the live booking flow computes them.
 */
class OwnerDemoSeeder extends Seeder
{
    /** A tiny 1x1 red PNG, used as a real (not broken) proof-of-posting photo. */
    private const PLACEHOLDER_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function run(): void
    {
        $owner = User::query()->where('email', 'owner@test.com')->first();
        $client = User::query()->where('email', 'client@test.com')->first();
        $admin = User::query()->where('email', 'admin@test.com')->first();

        if (! $owner || ! $client) {
            return;
        }

        $invoices = app(InvoiceService::class);

        $billboards = [
            'Gulshan-2 Circle Unipole' => '2027-03-15',
            'Gulshan-1 DCC Market Billboard' => '2026-10-01',
            'Banani 11 Road LED Screen' => '2027-01-20',
            'Banani Rail Crossing Unipole' => '2026-12-01',
        ];

        foreach ($billboards as $title => $permitExpiry) {
            Billboard::query()
                ->where('title', $title)
                ->update(['owner_id' => $owner->id, 'permit_expiry_date' => $permitExpiry]);
        }

        $ids = Billboard::query()->whereIn('title', array_keys($billboards))->pluck('id', 'title');

        $bookings = [
            [
                'billboard' => 'Gulshan-2 Circle Unipole',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'total_amount' => 180000,
                'advance_amount' => 54000,
                'status' => 'pending_admin_review',
                'brand_name' => 'Grameen Telecom',
                'ad_category' => 'Telecom',
                'campaign_description' => 'Festive season telecom offer campaign.',
            ],
            [
                'billboard' => 'Banani 11 Road LED Screen',
                'start_date' => '2026-09-10',
                'end_date' => '2026-10-09',
                'total_amount' => 260000,
                'advance_amount' => 78000,
                'status' => 'pending_admin_review',
                'brand_name' => 'Bata Bangladesh',
                'ad_category' => 'Retail / Footwear',
                'campaign_description' => 'New sneaker line launch digital campaign.',
            ],
            [
                'billboard' => 'Gulshan-1 DCC Market Billboard',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-30',
                'total_amount' => 220000,
                'advance_amount' => 66000,
                'status' => 'confirmed',
                'brand_name' => 'Pran-RFL Group',
                'ad_category' => 'FMCG',
                'campaign_description' => 'Seasonal grocery promotion billboard.',
            ],
            [
                'billboard' => 'Banani Rail Crossing Unipole',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-15',
                'total_amount' => 97500,
                'advance_amount' => 29250,
                'status' => 'paid_in_full',
                'brand_name' => 'Robi Axiata',
                'ad_category' => 'Telecom',
                'campaign_description' => 'Internet package summer campaign.',
            ],
            [
                'billboard' => 'Banani Rail Crossing Unipole',
                'start_date' => '2026-08-05',
                'end_date' => '2026-08-08',
                'total_amount' => 26000,
                'advance_amount' => 7800,
                'status' => 'rejected',
                'rejection_reason' => 'Requested dates conflict with scheduled maintenance.',
                'brand_name' => 'Aarong',
                'ad_category' => 'Fashion / Retail',
                'campaign_description' => 'Eid collection billboard promotion.',
            ],
            [
                'billboard' => 'Gulshan-2 Circle Unipole',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-15',
                'total_amount' => 90000,
                'advance_amount' => 27000,
                'status' => 'active',
                'brand_name' => 'Radiant Fashion House',
                'ad_category' => 'Fashion / Retail',
                'campaign_description' => 'Summer collection billboard placement.',
            ],

            // ---- Extra rows below so every stage on the Owner's own
            // "Booking Requests" page (which hides pending_admin_review —
            // the owner never sees a request before admin has forwarded
            // it) has 2-3 bookings instead of just 0-1. Same 4 owner
            // billboards and the same demo client, non-overlapping dates
            // per billboard, real Bangladeshi brand names for realism.

            // Gulshan-2 Circle Unipole
            [
                'billboard' => 'Gulshan-2 Circle Unipole',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-20',
                'total_amount' => 120000,
                'advance_amount' => 36000,
                'status' => 'pending_proof_review',
                'brand_name' => 'Nestlé Bangladesh',
                'ad_category' => 'FMCG',
                'campaign_description' => 'Maggi noodles festive-season campaign.',
            ],
            [
                'billboard' => 'Gulshan-2 Circle Unipole',
                'start_date' => '2026-08-16',
                'end_date' => '2026-08-25',
                'total_amount' => 60000,
                'advance_amount' => 18000,
                'status' => 'rejected',
                'rejection_reason' => 'Pharmaceutical ad content needs additional regulatory approval before it can go up.',
                'brand_name' => 'Renata Limited',
                'ad_category' => 'Pharmaceuticals',
                'campaign_description' => 'New OTC product awareness billboard.',
            ],
            [
                'billboard' => 'Gulshan-2 Circle Unipole',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-20',
                'total_amount' => 120000,
                'advance_amount' => 36000,
                'status' => 'confirmed',
                'brand_name' => 'bKash',
                'ad_category' => 'Fintech / Mobile Banking',
                'campaign_description' => 'Mobile banking cashback campaign billboard.',
            ],

            // Gulshan-1 DCC Market Billboard
            [
                'billboard' => 'Gulshan-1 DCC Market Billboard',
                'start_date' => '2026-06-16',
                'end_date' => '2026-06-30',
                'total_amount' => 110000,
                'advance_amount' => 33000,
                'status' => 'rejected',
                'rejection_reason' => 'Requested dates overlap with an already-confirmed campaign on this billboard.',
                'brand_name' => 'Brac Bank',
                'ad_category' => 'Banking',
                'campaign_description' => 'Credit card year-end offer campaign.',
            ],
            [
                'billboard' => 'Gulshan-1 DCC Market Billboard',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-15',
                'total_amount' => 110000,
                'advance_amount' => 33000,
                'status' => 'pending_proof_review',
                'brand_name' => 'Daraz Bangladesh',
                'ad_category' => 'E-commerce',
                'campaign_description' => 'Year-end mega sale billboard promotion.',
            ],
            [
                'billboard' => 'Gulshan-1 DCC Market Billboard',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-15',
                'total_amount' => 110000,
                'advance_amount' => 33000,
                'status' => 'paid_in_full',
                'brand_name' => 'Marks & Spencer Bangladesh',
                'ad_category' => 'Fashion / Retail',
                'campaign_description' => 'Autumn fashion collection billboard.',
            ],
            [
                'billboard' => 'Gulshan-1 DCC Market Billboard',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-15',
                'total_amount' => 110000,
                'advance_amount' => 33000,
                'status' => 'pending_owner_approval',
                'brand_name' => 'Square Toiletries',
                'ad_category' => 'Home & Personal Care',
                'campaign_description' => 'New detergent range billboard launch.',
            ],

            // Banani 11 Road LED Screen
            [
                'billboard' => 'Banani 11 Road LED Screen',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-15',
                'total_amount' => 130000,
                'advance_amount' => 39000,
                'status' => 'active',
                'brand_name' => 'City Bank',
                'ad_category' => 'Banking',
                'campaign_description' => 'Credit card cashback offer campaign.',
            ],
            [
                'billboard' => 'Banani 11 Road LED Screen',
                'start_date' => '2026-07-16',
                'end_date' => '2026-07-30',
                'total_amount' => 130000,
                'advance_amount' => 39000,
                'status' => 'pending_proof_review',
                'brand_name' => 'Unilever Bangladesh',
                'ad_category' => 'FMCG',
                'campaign_description' => 'Lux soap summer freshness campaign.',
            ],
            [
                'billboard' => 'Banani 11 Road LED Screen',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-15',
                'total_amount' => 130000,
                'advance_amount' => 39000,
                'status' => 'confirmed',
                'brand_name' => 'Apex Footwear',
                'ad_category' => 'Retail / Footwear',
                'campaign_description' => 'Winter footwear collection LED campaign.',
            ],

            // Banani Rail Crossing Unipole
            [
                'billboard' => 'Banani Rail Crossing Unipole',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-20',
                'total_amount' => 130000,
                'advance_amount' => 39000,
                'status' => 'active',
                'brand_name' => 'ACI Limited',
                'ad_category' => 'FMCG / Agro',
                'campaign_description' => 'Savlon hygiene awareness campaign.',
            ],
            [
                'billboard' => 'Banani Rail Crossing Unipole',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'total_amount' => 65000,
                'advance_amount' => 19500,
                'status' => 'pending_owner_approval',
                'brand_name' => 'Walton',
                'ad_category' => 'Home Appliances',
                'campaign_description' => 'Eid discount campaign for home appliances.',
            ],
            [
                'billboard' => 'Banani Rail Crossing Unipole',
                'start_date' => '2026-09-15',
                'end_date' => '2026-09-24',
                'total_amount' => 65000,
                'advance_amount' => 19500,
                'status' => 'paid_in_full',
                'brand_name' => 'Akij Group',
                'ad_category' => 'FMCG',
                'campaign_description' => 'New biscuit brand market entry campaign.',
            ],
        ];

        foreach ($bookings as $row) {
            $billboardId = $ids[$row['billboard']] ?? null;
            if (! $billboardId) {
                continue;
            }

            $isSettled = in_array($row['status'], ['paid_in_full', 'pending_proof_review', 'active'], true);
            $hasBalance = in_array($row['status'], ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'], true);

            $booking = Booking::query()->firstOrCreate(
                [
                    'billboard_id' => $billboardId,
                    'user_id' => $client->id,
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                ],
                [
                    'total_amount' => $row['total_amount'],
                    'advance_amount' => $row['advance_amount'],
                    'status' => $row['status'],
                    'rejection_reason' => $row['rejection_reason'] ?? null,
                    'brand_name' => $row['brand_name'],
                    'ad_category' => $row['ad_category'],
                    'campaign_description' => $row['campaign_description'],
                    'final_payment_due_at' => $hasBalance ? now()->addDays(7) : null,
                ]
            );

            $commissionRate = (float) Setting::get('commission_rate', 10);
            $commission = round($row['total_amount'] * $commissionRate / 100, 2);
            $ownerPayable = round($row['total_amount'] - $commission, 2);
            $balanceAmount = round($row['total_amount'] - $row['advance_amount'], 2);

            // Every seeded booking here has already cleared the advance —
            // that's the only way a booking reaches any of these stages.
            $advanceStatus = $row['status'] === 'rejected' ? 'refunded' : 'paid';

            Payment::query()->firstOrCreate(
                ['booking_id' => $booking->id, 'payment_type' => 'advance'],
                [
                    'amount' => $row['advance_amount'],
                    'status' => $advanceStatus,
                    'commission_amount' => $commission,
                    'owner_payable' => $ownerPayable,
                    'method' => 'bkash',
                    'paid_at' => now(),
                    'refunded_at' => $advanceStatus === 'refunded' ? now() : null,
                ]
            );

            if ($hasBalance) {
                Payment::query()->firstOrCreate(
                    ['booking_id' => $booking->id, 'payment_type' => 'balance'],
                    [
                        'amount' => $balanceAmount,
                        'status' => $isSettled ? 'paid' : 'pending',
                        'commission_amount' => 0,
                        'owner_payable' => $balanceAmount,
                        'method' => $isSettled ? 'bank' : null,
                        'paid_at' => $isSettled ? now() : null,
                    ]
                );
            }

            // Invoices, mirroring what the live payment flow would have issued:
            // an advance invoice once the advance clears, a final one once the
            // booking is settled. (A rejected booking's advance was refunded,
            // so it never gets one.)
            if ($advanceStatus === 'paid') {
                $invoices->issue($booking->fresh(), 'advance');

                if ($isSettled) {
                    $invoices->issue($booking->fresh(), 'final');
                }
            }

            // "active" = owner uploaded proof AND admin already verified it.
            // "pending_proof_review" = owner uploaded proof but admin hasn't
            // verified it yet — same photo, no verifier/verified_at.
            if (in_array($row['status'], ['active', 'pending_proof_review'], true) && $booking->proofOfPostings()->doesntExist()) {
                $path = 'proof-of-posting/demo-'.$booking->id.'.png';
                Storage::disk('public')->put($path, base64_decode(self::PLACEHOLDER_PNG_BASE64));

                $isVerified = $row['status'] === 'active';

                ProofOfPosting::query()->create([
                    'booking_id' => $booking->id,
                    'photo_path' => $path,
                    'status' => $isVerified ? 'verified' : 'pending',
                    'verified_by' => $isVerified ? $admin?->id : null,
                    'verified_at' => $isVerified ? now() : null,
                ]);
            }
        }

        // ---- Payout history: 2 already-completed monthly payout runs,
        // each covering a REAL subset of the settled advance payments
        // just seeded above (summed from their actual owner_payable
        // values, not made-up numbers) — matching the "payouts settle
        // on the 10th of each month" policy already shown as a note on
        // the Payouts page. Whatever's left unpaid after these two stays
        // as the current "Outstanding balance", same as real use.
        $this->seedHistoricalPayout(
            owner: $owner,
            admin: $admin,
            brands: ['Robi Axiata', 'Radiant Fashion House'],
            paidAt: '2026-06-10',
            method: 'bank',
            reference: 'PAYOUT-2026-06',
        );

        $this->seedHistoricalPayout(
            owner: $owner,
            admin: $admin,
            brands: ['Marks & Spencer Bangladesh', 'Akij Group', 'Daraz Bangladesh'],
            paidAt: '2026-07-10',
            method: 'bank',
            reference: 'PAYOUT-2026-07',
        );
    }

    /**
     * Creates one real Payout row covering exactly the given bookings'
     * advance payments (matched by brand_name, unique among this
     * seeder's own rows) — the same math + payout_id linking
     * PayoutService::payout() does for a live admin-triggered payout,
     * just scoped to a named subset so the demo shows 2 separate
     * historical runs instead of one lump sum covering everything.
     */
    private function seedHistoricalPayout(User $owner, ?User $admin, array $brands, string $paidAt, string $method, string $reference): void
    {
        $payments = Payment::query()
            ->where('payment_type', 'advance')
            ->whereNull('payout_id')
            ->whereHas(
                'booking',
                fn ($q) => $q->whereIn('brand_name', $brands)
                    ->whereHas('billboard', fn ($q2) => $q2->where('owner_id', $owner->id))
            )
            ->get();

        if ($payments->isEmpty()) {
            return;
        }

        $payout = Payout::query()->create([
            'owner_id' => $owner->id,
            'amount' => $payments->sum('owner_payable'),
            'method' => $method,
            'reference' => $reference,
            'paid_by' => $admin?->id,
            'paid_at' => $paidAt,
        ]);

        Payment::query()->whereIn('id', $payments->pluck('id'))->update(['payout_id' => $payout->id]);
    }
}
