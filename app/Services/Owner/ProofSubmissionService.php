<?php

namespace App\Services\Owner;

use App\Models\Booking;
use App\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProofSubmissionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  UploadedFile[]  $photos
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function submit(Booking $booking, int $ownerId, array $photos): array
    {
        if ($booking->billboard->owner_id !== $ownerId) {
            return ['ok' => false, 'status' => 403, 'message' => 'Forbidden: this booking is not for one of your billboards.'];
        }

        if ($booking->status !== 'paid_in_full') {
            return ['ok' => false, 'status' => 422, 'message' => 'Proof of posting can only be uploaded once the booking is paid in full.'];
        }

        foreach ($photos as $photo) {
            $path = Storage::disk('public')->putFile('proof-of-posting', $photo);

            $booking->proofOfPostings()->create([
                'photo_path' => $path,
                'status' => 'pending',
            ]);
        }

        $booking->update(['status' => 'pending_proof_review']);
        $booking = $booking->fresh(['billboard', 'proofOfPostings']);

        $this->notifications->notifyProofSubmitted($booking);

        return [
            'ok' => true,
            'status' => 201,
            'message' => 'Proof of posting submitted for review',
            'booking' => $booking,
        ];
    }
}
