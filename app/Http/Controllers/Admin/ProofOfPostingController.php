<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\RejectBookingRequest;
use App\Models\Booking;
use App\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProofOfPostingController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function verify(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->status !== 'pending_proof_review') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Only bookings with proof pending review can be verified.',
            ], 422);
        }

        $booking->proofOfPostings()->where('status', 'pending')->update([
            'status' => 'verified',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        $booking->update(['status' => 'active']);
        $booking = $booking->fresh(['billboard.owner', 'user']);

        $this->notifications->notifyProofVerified($booking);

        return response()->json([
            'success' => true,
            'data' => $booking,
            'message' => 'Proof verified, booking is now active',
        ]);
    }

    public function reject(RejectBookingRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->status !== 'pending_proof_review') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Only bookings with proof pending review can be rejected.',
            ], 422);
        }

        $reason = $request->validated('rejection_reason');

        $booking->proofOfPostings()->where('status', 'pending')->update([
            'status' => 'rejected',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Booking falls back to paid_in_full so the owner can re-upload proof.
        $booking->update(['status' => 'paid_in_full']);
        $booking = $booking->fresh(['billboard.owner']);

        $this->notifications->notifyProofRejected($booking, $reason);

        return response()->json([
            'success' => true,
            'data' => $booking,
            'message' => 'Proof rejected',
        ]);
    }
}
