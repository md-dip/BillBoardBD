<?php

namespace App\Services\Shared;

use App\Models\Payment;
use App\Notifications\NotificationService;

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
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly NotificationService $notifications,
    ) {}

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
        $invoice = $this->invoices->issue($booking, 'advance');

        $this->notifications->notifyAdvancePaid($booking, $invoice);
    }

    /**
     * Balance paid → the booking is paid in full (stage 4) and the final invoice
     * is issued.
     */
    private function afterBalance(Payment $payment): void
    {
        $payment->booking->update(['status' => 'paid_in_full']);

        $booking = $payment->booking->fresh(['billboard.owner', 'user']);
        $invoice = $this->invoices->issue($booking, 'final');

        $this->notifications->notifyBalancePaid($booking, $invoice);
    }
}
