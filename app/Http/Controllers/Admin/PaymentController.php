<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

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

        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification(
                $booking,
                'Final payment received',
                "The final payment for \"{$booking->billboard?->title}\" has been paid in full. Please install by the start date.",
            ));
        }
        foreach (User::query()->where('role', 'admin')->get() as $admin) {
            $admin->notify(new BookingStatusNotification($booking, 'Payment recorded', "Final payment recorded for \"{$booking->billboard?->title}\"."));
        }

        // Booking fully paid → the final invoice is generated now.
        $invoice = $this->invoices->issue($booking, 'final');
        $booking->user?->notify(new BookingStatusNotification(
            $booking,
            'Final invoice ready',
            "Invoice {$invoice->number} for \"{$booking->billboard?->title}\" is ready - your booking is now paid in full.",
        ));

        return response()->json([
            'success' => true,
            'data' => $payment->fresh(),
            'message' => 'Balance payment recorded',
        ], 201);
    }
}
