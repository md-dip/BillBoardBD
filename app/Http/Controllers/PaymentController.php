<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayPaymentRequest;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    /** Mock bKash/Nagad/bank/cash flow — no real payment gateway. */
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

        // Paying the advance moves the booking into admin review;
        // paying the balance is what fully completes the booking.
        if ($payment->payment_type === 'advance') {
            $payment->booking->update(['status' => 'pending', 'expires_at' => null]);
        } elseif ($payment->payment_type === 'balance') {
            $payment->booking->update(['status' => 'completed']);
        }

        return response()->json([
            'success' => true,
            'data' => $payment->fresh(),
            'message' => 'Payment confirmed',
        ]);
    }
}