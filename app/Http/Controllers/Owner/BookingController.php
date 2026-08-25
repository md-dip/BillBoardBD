<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectBookingRequest;
use App\Models\Booking;
use App\Services\BookingApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private readonly BookingApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $query = Booking::query()
            ->whereNotIn('status', ['held', 'pending_payment'])
            ->with(['billboard', 'user', 'payments'])
            ->whereHas('billboard', fn ($q) => $q->where('owner_id', $request->user()->id));

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->get(),
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

        $result = $this->approvals->approve($booking);

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

        $result = $this->approvals->reject($booking, $request->validated('rejection_reason'));

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }
}
