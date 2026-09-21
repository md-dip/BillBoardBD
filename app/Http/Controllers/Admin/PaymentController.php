<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\NotificationService;
use App\Services\Shared\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly NotificationService $notifications,
    ) {}

    public function recordBalance(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Only confirmed bookings can record a balance payment.',
            ], 422);
        }

        if ($booking->payments()->where('payment_type', 'balance')->where('status', 'paid')->exists()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Balance payment already recorded for this booking.',
            ], 422);
        }

        $validated = $request->validate([
            'method' => ['nullable', 'in:bkash,nagad,bank'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
        ]);

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

        return response()->json([
            'success' => true,
            'data' => $payment->fresh(),
            'message' => 'Balance payment recorded',
        ], 201);
    }
}
