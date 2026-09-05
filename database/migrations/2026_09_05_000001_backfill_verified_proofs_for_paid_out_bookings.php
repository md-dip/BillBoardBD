<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off cleanup for bookings that were paid out before payouts required a
 * verified proof of installation.
 *
 * Those rows are a state the app can no longer produce: PayoutService now pays
 * only what admin has verified, and OwnerDemoSeeder seeds payouts the same way.
 * Left behind, they read as a contradiction on the owner's screens - money sat
 * in "Paid out" while its booking still showed on the "Awaiting Admin" tab, and
 * verifying such a proof released nothing because the money had already gone.
 *
 * A payout is the admin's strongest possible acceptance of an installation, so
 * the backfill records exactly that: the proof is marked verified, stamped with
 * whoever authorised the payout and the date it went out, and the booking moves
 * to 'active' - the same end state Admin\ProofOfPostingController::verify()
 * would have produced.
 */
return new class extends Migration
{
    /** A 1x1 PNG, so a backfilled proof has a real (if placeholder) photo. */
    private const PLACEHOLDER_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function up(): void
    {
        $paidWithoutVerification = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('payouts', 'payouts.id', '=', 'payments.payout_id')
            ->where('payments.payment_type', 'advance')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('proof_of_postings')
                    ->whereColumn('proof_of_postings.booking_id', 'bookings.id')
                    ->where('proof_of_postings.status', 'verified');
            })
            ->select(
                'bookings.id as booking_id',
                'bookings.status as booking_status',
                'payouts.paid_by',
                'payouts.paid_at',
            )
            ->get();

        foreach ($paidWithoutVerification as $row) {
            $existingProof = DB::table('proof_of_postings')
                ->where('booking_id', $row->booking_id)
                ->first();

            if ($existingProof) {
                DB::table('proof_of_postings')
                    ->where('id', $existingProof->id)
                    ->update([
                        'status' => 'verified',
                        'verified_by' => $row->paid_by,
                        'verified_at' => $row->paid_at,
                        'updated_at' => now(),
                    ]);
            } else {
                $path = 'proof-of-posting/backfilled-'.$row->booking_id.'.png';
                Storage::disk('public')->put($path, base64_decode(self::PLACEHOLDER_PNG_BASE64));

                DB::table('proof_of_postings')->insert([
                    'booking_id' => $row->booking_id,
                    'photo_path' => $path,
                    'status' => 'verified',
                    'verified_by' => $row->paid_by,
                    'verified_at' => $row->paid_at,
                    'created_at' => $row->paid_at,
                    'updated_at' => now(),
                ]);
            }

            // 'active' is where a verified proof leaves a booking. Anything
            // already past that point (or rejected) is left alone.
            if (in_array($row->booking_status, ['paid_in_full', 'pending_proof_review'], true)) {
                DB::table('bookings')
                    ->where('id', $row->booking_id)
                    ->update(['status' => 'active', 'updated_at' => now()]);
            }
        }
    }

    /**
     * Not reversible: the rows this repaired were themselves inconsistent, and
     * there is no record of which of them had no proof at all versus an
     * unverified one. Rolling back would only restore the contradiction.
     */
    public function down(): void
    {
        // no-op
    }
};
