<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayPaymentRequest;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    /** Mock bKash/Nagad/bank flow — no real payment gateway. */
    public function pay(PayPaymentRequest $request, Payment $payment): JsonResponse
    {
        if ($payment->booking->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this is not your payment.',
            ], 403);
        }

        if ($payment->status === 'paid') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This payment has already been paid.',
            ], 422);
        }

        $payment->update([
            'method' => $request->validated('method'),
            'transaction_ref' => $request->validated('transaction_ref'),
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // Paying the advance moves the booking into admin review (stage 1);
        // paying the balance marks it paid in full (stage 4).
        if ($payment->payment_type === 'advance') {
            $payment->booking->update(['status' => 'pending_admin_review', 'expires_at' => null]);

            $booking = $payment->booking->fresh(['billboard']);
            $title = 'New booking request';
            $body = "A new booking for \"{$booking->billboard?->title}\" is awaiting your review.";

            foreach (User::query()->where('role', 'admin')->get() as $admin) {
                $admin->notify(new BookingStatusNotification($booking, $title, $body));
            }
        } elseif ($payment->payment_type === 'balance') {
            $payment->booking->update(['status' => 'paid_in_full']);

            $booking = $payment->booking->fresh(['billboard.owner']);
            $title = 'Final payment received';
            $body = "The final payment for \"{$booking->billboard?->title}\" has been paid in full. Please install by the start date.";

            if ($owner = $booking->billboard?->owner) {
                $owner->notify(new BookingStatusNotification($booking, $title, $body));
            }
            foreach (User::query()->where('role', 'admin')->get() as $admin) {
                $admin->notify(new BookingStatusNotification($booking, 'Payment recorded', "Final payment recorded for \"{$booking->billboard?->title}\"."));
            }
        }

        return response()->json([
            'success' => true,
            'data' => $payment->fresh(),
            'message' => 'Payment confirmed',
        ]);
    }
}