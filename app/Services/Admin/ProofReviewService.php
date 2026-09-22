<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Notifications\NotificationService;


class ProofReviewService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function verify(Booking $booking, int $verifierId): array
    {
        if ($booking->status !== 'pending_proof_review') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only bookings with proof pending review can be verified.'];
        }

        $booking->proofOfPostings()->where('status', 'pending')->update([
            'status' => 'verified',
            'verified_by' => $verifierId,
            'verified_at' => now(),
        ]);

        // Next status: active - terminal, this is the end of the pipeline.
        $booking->update(['status' => 'active']);
        $booking = $booking->fresh(['billboard.owner', 'user']);

        $this->notifications->notifyProofVerified($booking);

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Proof verified, booking is now active',
            'booking' => $booking,
        ];
    }

    /**
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function reject(Booking $booking, int $verifierId, string $reason): array
    {

        if ($booking->status !== 'pending_proof_review') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only bookings with proof pending review can be rejected.'];
        }

        $booking->proofOfPostings()->where('status', 'pending')->update([
            'status' => 'rejected',
            'verified_by' => $verifierId,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);


        $booking->update(['status' => 'paid_in_full']);
        $booking = $booking->fresh(['billboard.owner']);

        $this->notifications->notifyProofRejected($booking, $reason);

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Proof rejected',
            'booking' => $booking,
        ];
    }
}
