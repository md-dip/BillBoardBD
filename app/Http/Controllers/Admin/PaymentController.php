<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Admin\BalancePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly BalancePaymentService $balances) {}

    public function recordBalance(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['nullable', 'in:bkash,nagad,bank'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->balances->record($booking, $validated);

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['payment'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }
}
