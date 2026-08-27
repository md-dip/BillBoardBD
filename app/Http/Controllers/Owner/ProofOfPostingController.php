<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProofOfPostingRequest;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProofOfPostingController extends Controller
{
    public function store(StoreProofOfPostingRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->billboard->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this booking is not for one of your billboards.',
            ], 403);
        }

        if ($booking->status !== 'paid_in_full') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Proof of posting can only be uploaded once the booking is paid in full.',
            ], 422);
        }

        foreach ($request->file('photos') as $photo) {
            $path = Storage::disk('public')->putFile('proof-of-posting', $photo);

            $booking->proofOfPostings()->create([
                'photo_path' => $path,
                'status' => 'pending',
            ]);
        }

        $booking->update(['status' => 'pending_proof_review']);
        $booking = $booking->fresh(['billboard', 'proofOfPostings']);

        foreach (User::query()->where('role', 'admin')->get() as $admin) {
            $admin->notify(new BookingStatusNotification(
                $booking,
                'Installation proof submitted',
                "The owner uploaded proof of posting for \"{$booking->billboard?->title}\" — ready for verification.",
            ));
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
            'message' => 'Proof of posting submitted for review',
        ], 201);
    }
}
