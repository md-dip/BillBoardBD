<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Notifications\NotificationService;
use App\Services\Shared\RefundService;

/**
 * Stage 2 of the booking pipeline: the admin's first review, reached once the
 * client has paid the advance. Approving here only forwards the request to
 * the billboard owner for their own acceptance (stage 3) - it does not create
 * the balance payment or touch the calendar; that happens once the owner
 * actually accepts.
 *
 * Rejecting here is terminal. The advance the client already paid is not sent
 * back by the rejection itself - it is recorded as owed (see RefundService),
 * and the admin then pays it out by hand through SSLCommerz from the Rejected
 * tab. Recording the reason and returning the money are two separate steps on
 * purpose: the money leaves only when a person has actually sent it.
 */
class BookingApprovalService
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly NotificationService $notifications,
    ) {}

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

        $this->notifications->notifyBookingForwardedToOwner($booking);

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

        // The advance was paid up front, so rejecting leaves a debt behind.
        // Queued here, paid by the admin from the Rejected tab.
        $refund = $this->refunds->queueAdvanceRefund($booking);

        $booking = $booking->fresh(['billboard', 'user', 'payments']);

        $this->notifications->notifyBookingRejectedByAdmin($booking, $reason, $refund);

        return [
            'ok' => true,
            'status' => 200,
            'message' => $refund ? 'Booking rejected - the advance is now awaiting refund' : 'Booking rejected',
            'booking' => $booking,
        ];
    }
}
