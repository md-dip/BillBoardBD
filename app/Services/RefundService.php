<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;

/**
 * Mock refund gateway. When a booking is rejected after the client has already
 * paid the 30% advance, that advance goes straight back to the account it came
 * from - same method (bKash/Nagad/bank), with a fresh refund reference.
 * There is no real gateway call here, mirroring how "paying" is mocked across
 * the rest of the app (see PaymentController).
 */
class RefundService
{
    /**
     * Refund the paid advance on a booking, unless there isn't one or it has
     * already been refunded. Leaves behind two things: the original advance
     * row flipped to 'refunded' (so it drops out of revenue/payout maths), and
     * a dedicated 'refund' payment row that records where the money went.
     *
     * @return Payment|null the refund row, or null when there was nothing to refund
     */
    public function refundAdvance(Booking $booking): ?Payment
    {
        $advance = $booking->payments()
            ->where('payment_type', 'advance')
            ->where('status', 'paid')
            ->first();

        if (! $advance) {
            return null;
        }

        $advance->update([
            'status' => 'refunded',
            'refunded_at' => now(),
        ]);

        return Payment::query()->create([
            'booking_id' => $booking->id,
            'amount' => $advance->amount,
            'payment_type' => 'refund',
            'method' => $advance->method,                                  // back to the same account
            'transaction_ref' => 'RFND-'.$booking->id.'-'.now()->format('YmdHis'),
            'status' => 'refunded',
            'commission_amount' => 0,
            'owner_payable' => 0,
            'refunded_at' => now(),
        ]);
    }
}
