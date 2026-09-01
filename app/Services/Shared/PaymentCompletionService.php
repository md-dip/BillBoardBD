<?php

namespace App\Services\Shared;

use App\Models\Payment;
use App\Models\User;
use App\Notifications\BookingStatusNotification;

/**
 * The single place a payment becomes "paid" and the booking moves forward.
 *
 * Both the manual/mock endpoint (PaymentController::pay) and the SSLCommerz
 * callbacks (PaymentGatewayController) funnel through markPaid() so the booking
 * transition, invoice generation and notifications are identical no matter how
 * the money arrived. Idempotent - a replayed callback or a callback+IPN race is
 * a no-op once the payment is already paid.
 */
class PaymentCompletionService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /**
     * @param  array<string, mixed>  $attributes  extra columns to persist on the
     *                                            payment (method, transaction_ref,
     *                                            gateway_* …). status/paid_at are
     *                                            always forced here.
     */
    public function markPaid(Payment $payment, array $attributes = []): Payment
    {
        if ($payment->status === 'paid') {
            return $payment;
        }

        $payment->update([
            ...$attributes,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        if ($payment->payment_type === 'advance') {
            $this->afterAdvance($payment);
        } elseif ($payment->payment_type === 'balance') {
            $this->afterBalance($payment);
        }

        return $payment->fresh();
    }

    /**
     * Advance paid → the booking enters admin review (stage 1) and the advance
     * invoice is issued.
     */
    private function afterAdvance(Payment $payment): void
    {
        $payment->booking->update(['status' => 'pending_admin_review', 'expires_at' => null]);

        $booking = $payment->booking->fresh(['billboard', 'user']);
        $title = 'New booking request';
        $body = "A new booking for \"{$booking->billboard?->title}\" is awaiting your review.";

        foreach (User::query()->where('role', 'admin')->get() as $admin) {
            $admin->notify(new BookingStatusNotification($booking, $title, $body));
        }

        $invoice = $this->invoices->issue($booking, 'advance');
        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Advance invoice ready',
            "Invoice {$invoice->number} for your advance payment on \"{$booking->billboard?->title}\" is ready to view and download.",
        ));
    }

    /**
     * Balance paid → the booking is paid in full (stage 4) and the final invoice
     * is issued.
     */
    private function afterBalance(Payment $payment): void
    {
        $payment->booking->update(['status' => 'paid_in_full']);

        $booking = $payment->booking->fresh(['billboard.owner', 'user']);
        $title = 'Final payment received';
        $body = "The final payment for \"{$booking->billboard?->title}\" has been paid in full. Please install by the start date.";

        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification($booking, $title, $body));
        }
        foreach (User::query()->where('role', 'admin')->get() as $admin) {
            $admin->notify(new BookingStatusNotification($booking, 'Payment recorded', "Final payment recorded for \"{$booking->billboard?->title}\"."));
        }

        $invoice = $this->invoices->issue($booking, 'final');
        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Final invoice ready',
            "Invoice {$invoice->number} for \"{$booking->billboard?->title}\" is ready - your booking is now paid in full.",
        ));
    }
}
