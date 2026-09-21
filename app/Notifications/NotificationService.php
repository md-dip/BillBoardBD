<?php

namespace App\Notifications;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\ListingPayment;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class NotificationService
{
    // 1. BOOKING PAYMENTS

    public function notifyAdvancePaid(Booking $booking, Invoice $invoice): void
    {
        $this->notifyAdmins(new BookingStatusNotification(
            $booking,
            'New booking request',
            "A new booking for \"{$booking->billboard?->title}\" is awaiting your review.",
        ));

        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Advance invoice ready',
            "Invoice {$invoice->number} for your advance payment on \"{$booking->billboard?->title}\" is ready to view and download.",
        ));
    }


    public function notifyBalancePaid(Booking $booking, Invoice $invoice): void
    {
        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification(
                $booking,
                'Final payment received',
                "The final payment for \"{$booking->billboard?->title}\" has been paid in full. Please install by the start date.",
            ));
        }

        $this->notifyAdmins(new BookingStatusNotification(
            $booking,
            'Payment recorded',
            "Final payment recorded for \"{$booking->billboard?->title}\".",
        ));

        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Final invoice ready',
            "Invoice {$invoice->number} for \"{$booking->billboard?->title}\" is ready - your booking is now paid in full.",
        ));
    }


    // 2. BOOKING APPROVALS
    public function notifyBookingForwardedToOwner(Booking $booking): void
    {
        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification(
                $booking,
                'New booking request in your panel',
                "A booking request for \"{$booking->billboard?->title}\" is ready for your review.",
            ));
        }

        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Admin approved your request',
            "Your booking for \"{$booking->billboard?->title}\" was approved by admin and is now awaiting the owner's confirmation.",
        ));
    }

    /** Admin rejected the request */
    public function notifyBookingRejectedByAdmin(Booking $booking, string $reason, ?Payment $refund): void
    {
        $body = "Your booking for \"{$booking->billboard?->title}\" was rejected by admin. Reason: {$reason}";
        if ($refund) {
            $amount = '৳'.number_format((float) $refund->amount);
            $body .= " Your advance of {$amount} will be refunded - we will confirm here as soon as it has been sent.";
        }

        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            $refund ? 'Booking rejected - refund on the way' : 'Booking rejected',
            $body,
        ));
    }

    /** Owner accepted  */
    public function notifyBookingConfirmedByOwner(Booking $booking, Carbon $finalPaymentDueAt): void
    {
        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Owner accepted your booking',
            "The owner accepted your booking for \"{$booking->billboard?->title}\". Pay the remaining balance by {$finalPaymentDueAt->toDateString()}.",
        ));

        $this->notifyAdmins(new BookingStatusNotification(
            $booking,
            'Booking confirmed',
            "\"{$booking->billboard?->title}\" was confirmed by its owner and is now awaiting the final payment.",
        ));
    }

    /** Owner declined the request */
    public function notifyBookingDeclinedByOwner(Booking $booking, string $reason, ?Payment $refund): void
    {
        $clientBody = "The owner declined your booking for \"{$booking->billboard?->title}\". Reason: {$reason}";
        $adminBody = "\"{$booking->billboard?->title}\" was declined by its owner. Reason: {$reason}";

        if ($refund) {
            $amount = '৳'.number_format((float) $refund->amount);
            $clientBody .= " Your advance of {$amount} will be refunded - we will confirm here as soon as it has been sent.";
            $adminBody .= " The client's advance of {$amount} is now awaiting refund - pay it from the Rejected tab of Bookings.";
        }

        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            $refund ? 'Booking declined - refund on the way' : 'Booking declined',
            $clientBody,
        ));

        $this->notifyAdmins(new BookingStatusNotification(
            $booking,
            $refund ? 'Booking declined by owner - refund due' : 'Booking declined by owner',
            $adminBody,
        ));
    }


    // 3. INSTALLATION PROOF

    /** Owner uploaded proof of posting - ready for admin to verify. */
    public function notifyProofSubmitted(Booking $booking): void
    {
        $this->notifyAdmins(new BookingStatusNotification(
            $booking,
            'Installation proof submitted',
            "The owner uploaded proof of posting for \"{$booking->billboard?->title}\" - ready for verification.",
        ));
    }

    /** Admin verified the proof  */
    public function notifyProofVerified(Booking $booking): void
    {
        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Your campaign is live',
            "Your campaign on \"{$booking->billboard?->title}\" is now live. Installation has been verified by our admin team - you're welcome to visit the site and check the quality yourself, and we'd love to hear your feedback.",
        ));

        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification(
                $booking,
                'Installation confirmed',
                "Installation for \"{$booking->billboard?->title}\" was verified and the campaign is now active.",
            ));
        }
    }

    /** Admin rejected the proof  */
    public function notifyProofRejected(Booking $booking, string $reason): void
    {
        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification(
                $booking,
                'Installation proof rejected',
                "Your proof of posting for \"{$booking->billboard?->title}\" was rejected: {$reason}. Please re-upload.",
            ));
        }
    }

  
    // 4. REFUNDS

    public function notifyAdvanceRefunded(Booking $booking, Payment $refund): void
    {
        $amount = '৳'.number_format((float) $refund->amount);
        $method = $refund->method ? " to your {$refund->method} account" : '';
        $reference = $refund->transaction_ref ? " (ref {$refund->transaction_ref})" : '';

        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Advance refunded',
            "Your advance of {$amount} for \"{$booking->billboard?->title}\" has been refunded{$method}{$reference}.",
        ));
    }
    public function notifyListingFeeRefunded(Billboard $billboard, ListingPayment $payment): void
    {
        $amount = '৳'.number_format((float) $payment->amount);
        $method = $payment->refund_method ? " to your {$payment->refund_method} account" : '';
        $reference = $payment->refund_transaction_ref ? " (ref {$payment->refund_transaction_ref})" : '';

        $payment->owner?->notify(new BillboardListingNotification(
            $billboard,
            'Listing fee refunded',
            "Your listing fee of {$amount} for \"{$billboard->title}\" has been refunded{$method}{$reference}.",
        ));
    }

    // 5. BOARD LISTINGS + PAYOUTS

    public function notifyListingFeePaid(Billboard $billboard): void
    {
        $this->notifyAdmins(new BillboardListingNotification(
            $billboard,
            'New board listing request',
            "{$billboard->owner?->name} submitted \"{$billboard->title}\" for review.",
        ));

        $billboard->owner?->notify(new BillboardListingNotification(
            $billboard,
            'Listing fee received',
            "Your listing fee for \"{$billboard->title}\" is paid. The board is now awaiting admin review.",
        ));
    }

    /** Admin approved the board listing - it goes live on the public map. */
    public function notifyListingApproved(Billboard $billboard): void
    {
        $billboard->owner?->notify(new BillboardListingNotification(
            $billboard,
            'Board approved',
            "Your board \"{$billboard->title}\" has been approved and is now live on the map.",
        ));
    }

    /** Admin rejected the board listing, terminal. $refund is set once the listing-fee refund has been queued. */
    public function notifyListingRejected(Billboard $billboard, string $reason, ?ListingPayment $refund): void
    {
        $body = "Your board \"{$billboard->title}\" was rejected. Reason: {$reason}";
        if ($refund) {
            $amount = '৳'.number_format((float) $refund->amount);
            $body .= " Your listing fee of {$amount} will be refunded - we will confirm here as soon as it has been sent.";
        }

        $billboard->owner?->notify(new BillboardListingNotification(
            $billboard,
            $refund ? 'Board rejected - refund on the way' : 'Board rejected',
            $body,
        ));
    }

    /** Admin sent an owner their payout. */
    public function notifyPayoutSent(Payout $payout, User $owner): void
    {
        $owner->notify(new PayoutNotification($payout));
    }

    private function notifyAdmins(Notification $notification): void
    {
        User::query()->where('role', 'admin')->get()->each(
            fn (User $admin) => $admin->notify($notification)
        );
    }
}
