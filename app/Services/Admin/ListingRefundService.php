<?php

namespace App\Services\Admin;

use App\Models\Billboard;
use App\Models\ListingPayment;

/**
 * Mock refund gateway for the board listing fee. When admin rejects a board the
 * owner already paid for, that fee goes straight back to the account it came
 * from. No real gateway call - mirrors Admin\RefundService and how "paying" is
 * mocked across the rest of the app.
 */
class ListingRefundService
{
    /**
     * Refund the paid listing fee on a billboard, unless there isn't one or it
     * has already been refunded. Flips the paid row to 'refunded' (so it drops
     * out of revenue maths) and stamps a refund reference on it.
     *
     * @return ListingPayment|null the refunded row, or null when there was nothing to refund
     */
    public function refundListingFee(Billboard $billboard): ?ListingPayment
    {
        $payment = $billboard->listingPayments()
            ->where('status', 'paid')
            ->first();

        if (! $payment) {
            return null;
        }

        $payment->update([
            'status' => 'refunded',
            'refunded_at' => now(),
            'transaction_ref' => $payment->transaction_ref
                ?: 'RFND-LIST-'.$billboard->id.'-'.now()->format('YmdHis'),
        ]);

        return $payment->fresh();
    }
}
