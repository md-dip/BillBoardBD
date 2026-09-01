<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\RejectBookingRequest;
use App\Models\Booking;
use App\Services\Owner\OwnerAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private readonly OwnerAcceptanceService $acceptance) {}

    public function index(Request $request): JsonResponse
    {
        $query = Booking::query()
            ->whereNotIn('status', ['held', 'pending_payment', 'pending_admin_review'])
            ->with(['billboard', 'user', 'payments', 'proofOfPostings'])
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
