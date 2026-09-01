<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\RejectBookingRequest;
use App\Models\Booking;
use App\Notifications\BookingStatusNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProofOfPostingController extends Controller
{
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

        $booking->user->notify(new BookingStatusNotification(
            $booking,
            'Your campaign is live',
            "Your campaign on \"{$booking->billboard?->title}\" is now live. View the installation proof in My Bookings.",
        ));

        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification(
                $booking,
                'Installation confirmed',
                "Installation for \"{$booking->billboard?->title}\" was verified and the campaign is now active.",
            ));
        }

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

        if ($owner = $booking->billboard?->owner) {
            $owner->notify(new BookingStatusNotification(
                $booking,
                'Installation proof rejected',
                "Your proof of posting for \"{$booking->billboard?->title}\" was rejected: {$reason}. Please re-upload.",
            ));
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
            'message' => 'Proof rejected',
        ]);
    }
}
