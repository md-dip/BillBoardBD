<?php

namespace App\Services\Owner;

use App\Models\Billboard;
use App\Models\ListingPayment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The owner's side of the paid board-listing flow.
 *
 *   submit()       stores the two uploads, creates the Billboard row
 *                  (listing_status = pending_payment) and a pending
 *                  ListingPayment for the one-time fee.
 *   markFeePaid()  the single place the fee becomes "paid": flips the board to
 *                  pending_review and tells the admins. Idempotent, so a
 *                  replayed SSLCommerz callback / callback+IPN race is a no-op.
 *                  Mirrors Shared\PaymentCompletionService::markPaid().
 */
class ListingSubmissionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  array<string, mixed>  $data  validated billboard fields
     * @return array{billboard: Billboard, listing_payment: ListingPayment}
     */
    public function submit(User $owner, array $data, UploadedFile $photo, UploadedFile $permitDocument): array
    {
        $photoPath = Storage::disk('public')->putFile('board-photos', $photo);
        $permitPath = Storage::disk('public')->putFile('permit-documents', $permitDocument);

        $billboard = Billboard::query()->create([
            ...$data,
            'owner_id' => $owner->id,
            'photo' => $photoPath,
            'permit_document' => $permitPath,
            'listing_status' => 'pending_payment',
        ]);

        $payment = ListingPayment::query()->create([
            'billboard_id' => $billboard->id,
            'owner_id' => $owner->id,
            'amount' => (float) Setting::get('listing_fee', 5000),
            'status' => 'pending',
        ]);

        return ['billboard' => $billboard, 'listing_payment' => $payment];
    }

    /**
     * @param  array<string, mixed>  $attributes  extra columns to persist on the
     *                                            payment (method, transaction_ref,
     *                                            gateway_* …). status/paid_at are
     *                                            always forced here.
     */
    public function markFeePaid(ListingPayment $payment, array $attributes = []): ListingPayment
    {
        if ($payment->status === 'paid') {
            return $payment;
        }

        $payment->update([
            ...$attributes,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $billboard = $payment->billboard;
        $billboard->update([
            'listing_status' => 'pending_review',
            'submitted_at' => now(),
        ]);

        $billboard = $billboard->fresh(['owner']);

        $this->notifications->notifyListingFeePaid($billboard);

        return $payment->fresh();
    }
}
