<?php

namespace App\Services\Owner;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use App\Services\Shared\RefundService;

/**
 * Stage 3 of the booking pipeline: the billboard owner's own acceptance,
 * reached once admin has forwarded the request. Accepting is what actually
 * creates the balance payment and starts the countdown to the final-payment
 * due date - mirrors BookingApprovalService's shape for the admin's stage 2.
 *
 * Declining here is terminal and, exactly like an admin rejection, auto-refunds
 * the advance the client already paid to their source account (see RefundService).
 */
class OwnerAcceptanceService
{
    public function __construct(private readonly RefundService $refunds) {}

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

        $booking->user->notify(new BookingStatusNotification(
            $booking,
            'Owner accepted your booking',
            "The owner accepted your booking for \"{$booking->billboard?->title}\". Pay the remaining balance by {$dueAt->toDateString()}.",
        ));

        foreach (User::query()->where('role', 'admin')->get() as $admin) {
            $admin->notify(new BookingStatusNotification(
                $booking,
                'Booking confirmed',
                "\"{$booking->billboard?->title}\" was confirmed by its owner and is now awaiting the final payment.",
            ));
        }

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

        // The client paid the advance up front, so an owner decline auto-refunds
        // it to the account it came from - identical mechanism to an admin
        // rejection (mock, no real gateway call).
        $refund = $this->refunds->refundAdvance($booking);

        $booking = $booking->fresh(['billboard', 'user', 'payments']);

        $clientBody = "The owner declined your booking for \"{$booking->billboard?->title}\". Reason: {$reason}";
        $adminBody = "\"{$booking->billboard?->title}\" was declined by its owner. Reason: {$reason}";
        if ($refund) {
            $amount = '৳'.number_format((float) $refund->amount);
            $clientBody .= " Your advance of {$amount} has been refunded to your {$refund->method} account (ref {$refund->transaction_ref}).";
            $adminBody .= " The client's advance of {$amount} has been refunded to their {$refund->method} account (ref {$refund->transaction_ref}).";
        }

        $booking->user->notify(new BookingStatusNotification(
            $booking,
            $refund ? 'Booking declined - advance refunded' : 'Booking declined',
            $clientBody,
        ));

        foreach (User::query()->where('role', 'admin')->get() as $admin) {
            $admin->notify(new BookingStatusNotification(
                $booking,
                $refund ? 'Booking declined by owner - advance refunded' : 'Booking declined by owner',
                $adminBody,
            ));
        }

        return [
            'ok' => true,
            'status' => 200,
            'message' => $refund ? 'Booking rejected and advance refunded' : 'Booking rejected',
            'booking' => $booking,
        ];
    }
}
