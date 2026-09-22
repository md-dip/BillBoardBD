<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\RejectBookingRequest;
use App\Models\Booking;
use App\Services\Admin\ProofReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProofOfPostingController extends Controller
{
    public function __construct(private readonly ProofReviewService $proofs) {}

    public function verify(Request $request, Booking $booking): JsonResponse
    {
        $result = $this->proofs->verify($booking, $request->user()->id);

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }

    public function reject(RejectBookingRequest $request, Booking $booking): JsonResponse
    {
        $result = $this->proofs->reject($booking, $request->user()->id, $request->validated('rejection_reason'));

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }
}
