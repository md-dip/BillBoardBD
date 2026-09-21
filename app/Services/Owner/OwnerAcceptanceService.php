<?php

namespace App\Services\Owner;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\NotificationService;
use App\Services\Shared\RefundService;

/**
 * Stage 3 of the booking pipeline: the billboard owner's own acceptance,
 * reached once admin has forwarded the request. Accepting is what actually
 * creates the balance payment and starts the countdown to the final-payment
 * due date - mirrors BookingApprovalService's shape for the admin's stage 2.
 *
 * Declining here is terminal and, exactly like an admin rejection, records the
 * client's advance as owed back (see RefundService). The owner cannot send that
 * money themselves, so the admin is notified and pays it out by hand from the
 * Rejected tab of their own Bookings page.
 */
class OwnerAcceptanceService
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function accept(Booking $booking): array
    {
        if ($booking->status !== 'pending_owner_approval') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only bookings awaiting owner approval can be accepted.'];
        }

        $days = (int) Setting::get('final_payment_days', 7);
        $dueAt = now()->addDays($days);

        $booking->update([
            'status' => 'confirmed',
            'final_payment_due_at' => $dueAt,
        ]);

        $balanceAmount = round((float) $booking->total_amount - (float) $booking->advance_amount, 2);

        Payment::query()->create([
            'booking_id' => $booking->id,
            'amount' => $balanceAmount,
            'payment_type' => 'balance',
            'status' => 'pending',
            'commission_amount' => 0,
            'owner_payable' => $balanceAmount,
        ]);

        $booking = $booking->fresh(['billboard', 'user', 'payments']);

        $this->notifications->notifyBookingConfirmedByOwner($booking, $dueAt);

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Booking confirmed',
            'booking' => $booking,
        ];
    }

    /**
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function reject(Booking $booking, string $reason): array
    {
        if ($booking->status !== 'pending_owner_approval') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only bookings awaiting owner approval can be rejected.'];
        }

        $booking->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        // The client paid the advance up front, so an owner decline leaves the
        // same debt behind as an admin rejection - identical mechanism, and the
        // admin settles it either way.
        $refund = $this->refunds->queueAdvanceRefund($booking);

        $booking = $booking->fresh(['billboard', 'user', 'payments']);

        $this->notifications->notifyBookingDeclinedByOwner($booking, $reason, $refund);

        return [
            'ok' => true,
            'status' => 200,
            'message' => $refund ? 'Booking rejected - the advance is now awaiting refund' : 'Booking rejected',
            'booking' => $booking,
        ];
    }
}
