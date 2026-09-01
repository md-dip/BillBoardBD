<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectBookingRequest;
use App\Models\Booking;
use App\Services\BookingApprovalService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingApprovalService $approvals,
        private readonly InvoiceService $invoices,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Booking::query()
            ->whereNotIn('status', ['held', 'pending_payment'])
            ->with(['billboard', 'user', 'payments', 'proofOfPostings', 'invoices']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->get(),
            'message' => null,
        ]);
    }

    public function approve(Booking $booking): JsonResponse
    {
        $result = $this->approvals->approve($booking);

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }

    public function reject(RejectBookingRequest $request, Booking $booking): JsonResponse
    {
        $result = $this->approvals->reject($booking, $request->validated('rejection_reason'));

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }

    /**
     * The admin's copy of a booking's invoice - same document the client sees,
     * plus the platform commission and payable-to-owner breakdown.
     */
    public function invoice(Request $request, Booking $booking): JsonResponse
    {
        $invoice = $booking->invoices()
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')          // tiebreaker: the 'final' row is always the newer one
            ->first();

        if (! $invoice) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'No invoice for this booking yet.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->invoices->payload($invoice, showOwnerSplit: true),
            'message' => null,
        ]);
    }
}