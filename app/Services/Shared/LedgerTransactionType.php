<?php

namespace App\Services\Shared;


class LedgerTransactionType
{
    public const BOOKING_ADVANCE = 'booking_advance';

    public const BOOKING_BALANCE = 'booking_balance';

    public const LISTING_FEE = 'listing_fee';

    /** @param  string  $paymentType  a payments.payment_type column value ('advance' or 'balance') */
    public static function forBookingPayment(string $paymentType): string
    {
        return $paymentType === 'balance' ? self::BOOKING_BALANCE : self::BOOKING_ADVANCE;
    }
}
