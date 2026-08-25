<?php

namespace Database\Seeders;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Gives the demo owner account (owner@test.com) real, non-empty data to show
 * on every part of the Owner Dashboard: a handful of billboards it owns, and
 * bookings against them in every status (pending/approved/completed/rejected)
 * with the matching payment rows, computed the same way the live booking
 * flow computes them (see BookingController::submitCampaign and
 * BookingApprovalService).
 */
class OwnerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', 'owner@test.com')->first();
        $client = User::query()->where('email', 'client@test.com')->first();

        if (! $owner || ! $client) {
            return;
        }

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
                'status' => 'pending',
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
                'status' => 'pending',
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
                'status' => 'approved',
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
                'status' => 'completed',
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
        ];

        foreach ($bookings as $row) {
            $billboardId = $ids[$row['billboard']] ?? null;
            if (! $billboardId) {
                continue;
            }

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
                ]
            );

            $commission = round($row['total_amount'] * 0.10, 2);
            $ownerPayable = round($row['total_amount'] - $commission, 2);
            $balanceAmount = round($row['total_amount'] - $row['advance_amount'], 2);

            // Every seeded booking here has already cleared the advance —
            // that's the only way a booking reaches pending/approved/etc.
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

            if (in_array($row['status'], ['approved', 'completed'], true)) {
                Payment::query()->firstOrCreate(
                    ['booking_id' => $booking->id, 'payment_type' => 'balance'],
                    [
                        'amount' => $balanceAmount,
                        'status' => $row['status'] === 'completed' ? 'paid' : 'pending',
                        'commission_amount' => 0,
                        'owner_payable' => $balanceAmount,
                        'method' => $row['status'] === 'completed' ? 'cash' : null,
                        'paid_at' => $row['status'] === 'completed' ? now() : null,
                    ]
                );
            }
        }
    }
}
