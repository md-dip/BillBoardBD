<?php

namespace App\Services\Shared;

use App\Models\Booking;

/**
 * When booking money counts as real - the single place that rule lives, so the
 * admin dashboard (Admin\ReportController) and the owner dashboard
 * (Owner\BookingController) can never drift apart on it.
 *
 * The rule: money counts once it can no longer be refunded.
 *
 *   advance - the client pays it to submit the request, and admin OR the owner
 *             rejecting sends it straight back (Shared\RefundService). It only
 *             counts once the booking has cleared BOTH approvals.
 *   balance - only payable after that point, with no rejection left that could
 *             return it, so it counts the moment it is paid.
 */
class RevenueRecognitionService
{
    /**
     * Booking statuses where both approvals are in - admin has reviewed it and
     * the owner has accepted - so the advance is no longer refundable.
     * Anything earlier (pending_admin_review, pending_owner_approval) is money
     * the platform may still have to give back.
     */
    public const EARNED_BOOKING_STATUSES = ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'];

    public function isEarned(?string $bookingStatus): bool
    {
        return in_array($bookingStatus, self::EARNED_BOOKING_STATUSES, true);
    }

    /**
     * Cash actually collected on a booking that the platform gets to keep:
     * the advance once both approvals are in, plus the balance once it is paid.
     * A booking still awaiting either approval collects 0, however much has
     * been paid into it.
     *
     * Expects the `payments` relation to be loaded.
     */
    public function collectedOn(Booking $booking): float
    {
        $advanceCounts = $this->isEarned($booking->status);

        $collected = $booking->payments
            ->filter(fn ($payment) => $payment->status === 'paid'
                && ($payment->payment_type === 'balance' || $advanceCounts))
            ->sum(fn ($payment) => (float) $payment->amount);

        return round((float) $collected, 2);
    }
}
