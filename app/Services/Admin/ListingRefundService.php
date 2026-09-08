<?php

namespace App\Services\Admin;

use App\Models\Billboard;
use App\Models\ListingPayment;
use App\Notifications\BillboardListingNotification;

/**
 * The owner's listing-fee refund, mirroring Shared\RefundService for bookings:
 * rejecting a board records that the fee is owed back, and the admin pays it by
 * hand through SSLCommerz from the Listing refunds tab.
 *
 * There is no second row here the way a booking gets a 'refund' payment - a
 * board has exactly one listing_payments row, and the refund leg writes to its
 * own refund_* columns so the owner's original payment reference survives
 * intact.
 *
 * "A refund is owed" is therefore not a status of its own: it is a rejected
 * board whose fee is still 'paid'. A board can only be rejected out of
 * 'pending_review', which it can only reach by paying, so the pair is
 * unambiguous.
 */
class ListingRefundService
{
    /**
     * The listing fee still owed back on a board, or null when there is nothing
     * to pay - the board was not rejected, the fee was never paid, or it has
     * already been refunded. What the admin's Refund button is enabled by.
     */
    public function pendingRefundFor(Billboard $billboard): ?ListingPayment
    {
        if ($billboard->listing_status !== 'rejected') {
            return null;
        }

        return $billboard->listingPayments()
            ->where('status', 'paid')
            ->first();
    }

    /**
     * Settle a listing-fee refund once the admin's SSLCommerz payment has been
     * validated. Flips the row to 'refunded' so it drops out of the revenue
     * maths and stamps the refund leg's own references onto it. Idempotent - a
     * replayed callback or a callback/IPN race is a no-op.
     *
     * @param  array<string, mixed>  $attributes  refund_* gateway columns.
     *                                            status/refunded_at are forced.
     */
    public function markRefunded(ListingPayment $payment, array $attributes = []): ListingPayment
    {
        if ($payment->status === 'refunded') {
            return $payment;
        }

        $payment->update([
            ...$attributes,
            'status' => 'refunded',
            'refunded_at' => now(),
        ]);

        $payment = $payment->fresh(['billboard', 'owner']);
        $billboard = $payment->billboard;

        $amount = '৳'.number_format((float) $payment->amount);
        $method = $payment->refund_method ? " to your {$payment->refund_method} account" : '';
        $reference = $payment->refund_transaction_ref ? " (ref {$payment->refund_transaction_ref})" : '';

        $payment->owner?->notify(new BillboardListingNotification(
            $billboard,
            'Listing fee refunded',
            "Your listing fee of {$amount} for \"{$billboard?->title}\" has been refunded{$method}{$reference}.",
        ));

        return $payment;
    }
}
