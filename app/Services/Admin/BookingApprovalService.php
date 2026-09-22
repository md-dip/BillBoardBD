<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Notifications\NotificationService;
use App\Services\Shared\RefundService;


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
