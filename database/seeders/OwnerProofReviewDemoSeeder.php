<?php

namespace Database\Seeders;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\ProofOfPosting;
use App\Models\Setting;
use App\Models\User;
use App\Services\Shared\InvoiceService;
use Database\Seeders\Concerns\SeedsRealisticPaymentDates;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Tops the demo owner up with bookings sitting at the "Awaiting Admin" stage -
 * paid in full, proof of installation uploaded, waiting on the admin to verify
 * it.
 *
 * That stage empties itself in normal use: every booking on it is one admin
 * click away from moving on, so a demo that has just been driven through the
 * flow leaves the tab (and the owner's "Awaiting verification" figure) at zero
 * with nothing to show. This keeps 3 bookings parked there.
 *
 * Only tops up what is missing, so running it repeatedly - or after the admin
 * verifies a couple - never piles up extra bookings.
 */
class OwnerProofReviewDemoSeeder extends Seeder
{
    use SeedsRealisticPaymentDates;

    /** How many bookings to keep parked on the Awaiting Admin tab. */
    private const TARGET = 3;

    /** A 1x1 PNG, so the uploaded proof is a real (if placeholder) photo. */
    private const PLACEHOLDER_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function run(): void
    {
        $owner = User::query()->where('email', 'owner@test.com')->first();
        $client = User::query()->where('email', 'client@test.com')->first();

        if (! $owner || ! $client) {
            return;
        }

        $alreadyParked = Booking::query()
            ->where('status', 'pending_proof_review')
            ->whereHas('billboard', fn ($q) => $q->where('owner_id', $owner->id))
            ->count();

        $missing = self::TARGET - $alreadyParked;

        if ($missing <= 0) {
            return;
        }

        // 2027 dates on purpose: every other seeded booking sits in 2026, so
        // these can never collide with an existing campaign on the same board.
        $rows = [
            [
                'billboard' => 'Gulshan-2 Circle Unipole',
                'start_date' => '2027-01-05',
                'end_date' => '2027-01-24',
                'total_amount' => 120000,
                'advance_amount' => 36000,
                'brand_name' => 'Ispahani Tea',
                'ad_category' => 'FMCG',
                'campaign_description' => 'Winter tea campaign, installed and awaiting admin sign-off.',
            ],
            [
                'billboard' => 'Gulshan-1 DCC Market Billboard',
                'start_date' => '2027-02-01',
                'end_date' => '2027-02-15',
                'total_amount' => 110000,
                'advance_amount' => 33000,
                'brand_name' => 'Meena Bazar',
                'ad_category' => 'Retail / Grocery',
                'campaign_description' => 'Monthly grocery offer campaign, proof submitted.',
            ],
            [
                'billboard' => 'Banani 11 Road LED Screen',
                'start_date' => '2027-02-10',
                'end_date' => '2027-02-24',
                'total_amount' => 130000,
                'advance_amount' => 39000,
                'brand_name' => 'Fresh Cement',
                'ad_category' => 'Construction',
                'campaign_description' => 'Brand awareness LED campaign, proof submitted.',
            ],
        ];

        $invoices = app(InvoiceService::class);
        $commissionRate = (float) Setting::get('commission_rate', 10);

        foreach (array_slice($rows, 0, $missing) as $row) {
            $billboard = Billboard::query()
                ->where('owner_id', $owner->id)
                ->where('title', $row['billboard'])
                ->first();

            if (! $billboard) {
                continue;
            }

            $booking = Booking::query()->firstOrCreate(
                [
                    'billboard_id' => $billboard->id,
                    'user_id' => $client->id,
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                ],
                [
                    'total_amount' => $row['total_amount'],
                    'advance_amount' => $row['advance_amount'],
                    'status' => 'pending_proof_review',
                    'brand_name' => $row['brand_name'],
                    'ad_category' => $row['ad_category'],
                    'campaign_description' => $row['campaign_description'],
                    'final_payment_due_at' => now()->addDays(7),
                ]
            );

            // Commission is taken against the full total and frozen onto the
            // advance row, exactly as Client\BookingController writes it.
            $commission = round($row['total_amount'] * $commissionRate / 100, 2);
            $balanceAmount = round($row['total_amount'] - $row['advance_amount'], 2);

            // Dated off the campaign, not off "now" - same rule the rest of
            // the demo money follows (see SeedsRealisticPaymentDates), so the
            // revenue-by-month chart keeps a real spread of months.
            $advance = Payment::query()->firstOrCreate(
                ['booking_id' => $booking->id, 'payment_type' => 'advance'],
                [
                    'amount' => $row['advance_amount'],
                    'status' => 'paid',
                    'commission_amount' => $commission,
                    'owner_payable' => round($row['total_amount'] - $commission, 2),
                    'method' => 'bkash',
                ]
            );

            $this->redatePayment($advance, $this->advancePaidAt($row['start_date']));

            $balance = Payment::query()->firstOrCreate(
                ['booking_id' => $booking->id, 'payment_type' => 'balance'],
                [
                    'amount' => $balanceAmount,
                    'status' => 'paid',
                    'commission_amount' => 0,
                    'owner_payable' => $balanceAmount,
                    'method' => 'bank',
                ]
            );

            $this->redatePayment($balance, $this->balancePaidAt($row['start_date']));

            // Dated by the payment each one bills for, same as above.
            $this->redateInvoice($invoices->issue($booking->fresh(), 'advance'), $advance->paid_at);
            $this->redateInvoice($invoices->issue($booking->fresh(), 'final'), $balance->paid_at);

            // The uploaded proof itself: pending, so it is the admin's move.
            if ($booking->proofOfPostings()->doesntExist()) {
                $path = 'proof-of-posting/awaiting-'.$booking->id.'.png';
                Storage::disk('public')->put($path, base64_decode(self::PLACEHOLDER_PNG_BASE64));

                ProofOfPosting::query()->create([
                    'booking_id' => $booking->id,
                    'photo_path' => $path,
                    'status' => 'pending',
                ]);
            }
        }

        $this->command?->info('Awaiting Admin tab topped up to '.self::TARGET.' booking(s).');
    }
}
