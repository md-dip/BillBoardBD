<?php

namespace App\Services\Shared;

use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\BookingStatusNotification;

/**
 * The client's advance refund, in two halves.
 *
 * A rejection no longer moves the money by itself. Rejecting only records that
 * a refund is OWED - a 'refund' payment row left 'pending' - and the admin then
 * pays it by hand through SSLCommerz from the Rejected tab (see
 * Admin\RefundController). That is what makes an owner's decline work too: the
 * owner cannot pay anyone, so the admin is notified and settles it themselves.
 *
 *   queueAdvanceRefund()  called by the rejection, records the debt.
 *   markRefunded()        called by the gateway callback, settles it.
 *
 * The split matters for the money reports: until markRefunded() runs, the
 * client's advance is still 'paid'. Nothing leaks into revenue because every
 * ledger also requires the booking to have cleared both approvals and a
 * rejected booking never has (see RevenueRecognitionService).
 */
class RefundService
{
    /**
     * Record that the advance on a rejected booking has to go back, without
     * moving any money. Idempotent - a booking never queues two refunds.
     *
     * @return Payment|null the pending refund row, or null when the client
     *                      never paid an advance in the first place
     */
    public function queueAdvanceRefund(Booking $booking): ?Payment
    {
        $existing = $booking->payments()
            ->where('payment_type', 'refund')
            ->first();

        if ($existing) {
            return $existing;
        }

        $advance = $booking->payments()
            ->where('payment_type', 'advance')
            ->where('status', 'paid')
            ->first();

        if (! $advance) {
            return null;
        }

        return Payment::query()->create([
            'booking_id' => $booking->id,
            'amount' => $advance->amount,
            'payment_type' => 'refund',
            // method and transaction_ref stay null until the admin has actually
            // sent it - they are filled in from the gateway's validation.
            'status' => 'pending',
            'commission_amount' => 0,
            'owner_payable' => 0,
        ]);
    }

    /**
     * Settle a queued refund once the admin's SSLCommerz payment has been
     * validated. Leaves behind the same two things the old automatic refund
     * did: the original advance flipped to 'refunded' so it drops out of the
     * revenue and payout maths, and the refund row recording where the money
     * went. Idempotent - a replayed callback or a callback/IPN race is a no-op.
     *
     * @param  array<string, mixed>  $attributes  gateway columns (method,
     *                                            transaction_ref, gateway_*).
     *                                            status/refunded_at are forced.
     */
    public function markRefunded(Payment $refund, array $attributes = []): Payment
    {
        if ($refund->status === 'refunded') {
            return $refund;
        }

        $refund->update([
            ...$attributes,
            'status' => 'refunded',
            'refunded_at' => now(),
        ]);

        $booking = $refund->booking;

        $booking->payments()
            ->where('payment_type', 'advance')
            ->where('status', 'paid')
            ->update(['status' => 'refunded', 'refunded_at' => now()]);

        $refund = $refund->fresh();
        $booking = $booking->fresh(['billboard', 'user']);

        $amount = '৳'.number_format((float) $refund->amount);
        $method = $refund->method ? " to your {$refund->method} account" : '';
        $reference = $refund->transaction_ref ? " (ref {$refund->transaction_ref})" : '';

        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Advance refunded',
            "Your advance of {$amount} for \"{$booking->billboard?->title}\" has been refunded{$method}{$reference}.",
        ));

        return $refund;
    }

    /**
     * The refund still owed on a booking, or null when there is nothing to pay.
     * What the admin's Refund button is enabled by.
     */
    public function pendingRefundFor(Booking $booking): ?Payment
    {
        return $booking->payments()
            ->where('payment_type', 'refund')
            ->where('status', 'pending')
            ->first();
    }
}
