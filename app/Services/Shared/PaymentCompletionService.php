<?php

namespace App\Services\Shared;

use App\Models\Payment;
use App\Notifications\NotificationService;


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
        // Next status: pending_admin_review - now waits on
        // BookingApprovalService::approve()/reject().
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
        // Next status: paid_in_full - now waits on the owner to upload proof
        // of posting, via Owner\ProofSubmissionService::submit().
        $payment->booking->update(['status' => 'paid_in_full']);

        $booking = $payment->booking->fresh(['billboard.owner', 'user']);
        $invoice = $this->invoices->issue($booking, 'final');

        $this->notifications->notifyBalancePaid($booking, $invoice);
    }
}
