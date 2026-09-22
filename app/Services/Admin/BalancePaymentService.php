<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\NotificationService;
use App\Services\Shared\InvoiceService;


class BalancePaymentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{method?: string|null, transaction_ref?: string|null}  $validated
     * @return array{ok: bool, status: int, message: string, payment?: Payment}
     */
    public function record(Booking $booking, array $validated): array
    {

        if ($booking->status !== 'confirmed') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only confirmed bookings can record a balance payment.'];
        }

        if ($booking->payments()->where('payment_type', 'balance')->where('status', 'paid')->exists()) {
            return ['ok' => false, 'status' => 422, 'message' => 'Balance payment already recorded for this booking.'];
        }

        $balanceAmount = round((float) $booking->total_amount - (float) $booking->advance_amount, 2);

        // Update the existing pending 'balance' row created at owner acceptance, else create one
        $payment = $booking->payments()->where('payment_type', 'balance')->first();

        if ($payment) {
            $payment->update([
                'method' => $validated['method'] ?? 'bank',
                'transaction_ref' => $validated['transaction_ref'] ?? null,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        } else {
            $payment = Payment::query()->create([
                'booking_id' => $booking->id,
                'amount' => $balanceAmount,
                'payment_type' => 'balance',
                'method' => $validated['method'] ?? 'bank',
                'transaction_ref' => $validated['transaction_ref'] ?? null,
                'status' => 'paid',
                'commission_amount' => 0,
                'owner_payable' => $balanceAmount,
                'paid_at' => now(),
            ]);
        }

        
        $booking->update(['status' => 'paid_in_full']);
        $booking = $booking->fresh(['billboard.owner', 'user']);

        // Booking fully paid → the final invoice is generated now.
        $invoice = $this->invoices->issue($booking, 'final');

        $this->notifications->notifyBalancePaid($booking, $invoice);

        return [
            'ok' => true,
            'status' => 201,
            'message' => 'Balance payment recorded',
            'payment' => $payment->fresh(),
        ];
    }
}
