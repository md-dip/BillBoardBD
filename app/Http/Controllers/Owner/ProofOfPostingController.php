<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StoreProofOfPostingRequest;
use App\Models\Booking;
use App\Services\Owner\ProofSubmissionService;
use Illuminate\Http\JsonResponse;

class ProofOfPostingController extends Controller
{
    public function __construct(private readonly ProofSubmissionService $proofs) {}

    public function store(StoreProofOfPostingRequest $request, Booking $booking): JsonResponse
    {
        $result = $this->proofs->submit($booking, $request->user()->id, $request->file('photos'));

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }
}
