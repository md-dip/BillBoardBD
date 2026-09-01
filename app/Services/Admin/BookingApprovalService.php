<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Notifications\BookingStatusNotification;

/**
 * Stage 2 of the booking pipeline: the admin's first review, reached once the
 * client has paid the advance. Approving here only forwards the request to
 * the billboard owner for their own acceptance (stage 3) - it does not create
 * the balance payment or touch the calendar; that happens once the owner
 * actually accepts.
 *
 * Rejecting here is terminal: the advance the client already paid is auto
 * refunded to their source account (see RefundService).
 */
class BookingApprovalService
{
    public function __construct(private readonly RefundService $refunds) {}

    /**
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function approve(Booking $booking): array
    {
        if ($booking->status !== 'pending_admin_review') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only bookings awaiting admin review can be approved.'];
        }

        $booking->update(['status' => 'pending_owner_approval']);
        $booking = $booking->fresh(['billboard.owner', 'user']);

        $title = 'New booking request in your panel';
        $body = "A booking request for \"{$booking->billboard?->title}\" is ready for your review.";
        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification($booking, $title, $body));
        }

        $booking->user->notify(new BookingStatusNotification(
            $booking,
            'Admin approved your request',
            "Your booking for \"{$booking->billboard?->title}\" was approved by admin and is now awaiting the owner's confirmation.",
        ));

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Booking approved',
            'booking' => $booking,
        ];
    }

    /**
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function reject(Booking $booking, string $reason): array
    {
        if ($booking->status !== 'pending_admin_review') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only bookings awaiting admin review can be rejected.'];
        }

        $booking->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        // The advance was paid up front, so a rejection auto-refunds it to the
        // account it came from (mock - no real gateway call, matches how
        // "paying" is mocked elsewhere).
        $refund = $this->refunds->refundAdvance($booking);

        $booking = $booking->fresh(['billboard', 'user', 'payments']);

        $body = "Your booking for \"{$booking->billboard?->title}\" was rejected by admin. Reason: {$reason}";
        if ($refund) {
            $amount = '৳'.number_format((float) $refund->amount);
            $body .= " Your advance of {$amount} has been refunded to your {$refund->method} account (ref {$refund->transaction_ref}).";
        }

        $booking->user->notify(new BookingStatusNotification(
            $booking,
            $refund ? 'Booking rejected - advance refunded' : 'Booking rejected',
            $body,
        ));

        return [
            'ok' => true,
            'status' => 200,
            'message' => $refund ? 'Booking rejected and advance refunded' : 'Booking rejected',
            'booking' => $booking,
        ];
    }
}
