<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\RejectBookingRequest;
use App\Models\Booking;
use App\Services\Owner\OwnerAcceptanceService;
use App\Services\Shared\RevenueRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly OwnerAcceptanceService $acceptance,
        private readonly RevenueRecognitionService $revenue,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Booking::query()
            ->whereNotIn('status', ['held', 'pending_payment', 'pending_admin_review'])
            ->with(['billboard', 'user', 'payments', 'proofOfPostings'])
            ->whereHas('billboard', fn ($q) => $q->where('owner_id', $request->user()->id));

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // collected_amount is what this booking has actually earned the owner a
        // share of so far: nothing while it still awaits approval, the advance
        // once both approvals are in, then the full amount after the balance is
        // paid. Computed here rather than in the dashboard so the owner's
        // Revenue tile and the admin's report cannot disagree.
        $bookings = $query->latest()->get()->each(function (Booking $booking) {
            $booking->collected_amount = $this->revenue->collectedOn($booking);
        });

        return response()->json([
            'success' => true,
            'data' => $bookings,
            'message' => null,
        ]);
    }

    public function approve(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->billboard->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this booking is not for one of your billboards.',
            ], 403);
        }

        $result = $this->acceptance->accept($booking);

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }

    public function reject(RejectBookingRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->billboard->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this booking is not for one of your billboards.',
            ], 403);
        }

        $result = $this->acceptance->reject($booking, $request->validated('rejection_reason'));

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }
}
