<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\PayPaymentRequest;
use App\Models\Payment;
use App\Services\Shared\PaymentCompletionService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentCompletionService $completion) {}

    /**
     * Manual / offline payment record. The client UI now checks out through the
     * real gateway (see PaymentGatewayController); this endpoint stays for
     * tests and manual reconciliation and shares the same completion logic.
     */
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

        $this->completion->markPaid($payment, [
            'method' => $request->validated('method'),
            'transaction_ref' => $request->validated('transaction_ref'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $payment->fresh(),
            'message' => 'Payment confirmed',
        ]);
    }
}
