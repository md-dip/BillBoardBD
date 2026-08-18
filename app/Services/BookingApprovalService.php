<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;

class BookingApprovalService
{
    public function approve(Booking $booking): array
    {
        if ($booking->status !== 'pending') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only pending bookings can be approved.'];
        }

        $conflict = $booking->billboard->activeBookings()
            ->where('id', '!=', $booking->id)
            ->where('start_date', '<=', $booking->end_date)
            ->where('end_date', '>=', $booking->start_date)
            ->exists();

        if ($conflict) {
            return [
                'ok' => false,
                'status' => 409,
                'message' => 'This booking now conflicts with another booking for the same dates.',
            ];
        }

        $booking->update(['status' => 'approved']);

        $balanceAmount = round((float) $booking->total_amount - (float) $booking->advance_amount, 2);

        Payment::query()->create([
            'booking_id' => $booking->id,
            'amount' => $balanceAmount,
            'payment_type' => 'balance',
            'status' => 'pending',
            'commission_amount' => 0,
            'owner_payable' => $balanceAmount,
        ]);

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Booking approved',
            'booking' => $booking->fresh(['billboard', 'user', 'payments']),
        ];
    }

    public function reject(Booking $booking, string $reason): array
    {
        if ($booking->status !== 'pending') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only pending bookings can be rejected.'];
        }

        $booking->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $booking->payments()
            ->where('payment_type', 'advance')
            ->where('status', 'paid')
            ->update(['status' => 'refunded', 'refunded_at' => now()]);

        return ['ok' => true, 'status' => 200, 'message' => 'Booking rejected', 'booking' => $booking->fresh()];
    }
}