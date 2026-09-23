<?php

namespace App\Services\Client;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;


class BookingLifecycleService
{
    public function __construct(private readonly BookingPricingService $pricing) {}

    /**
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function hold(Billboard $billboard, int $userId, string $startDate, string $endDate): array
    {
        if ($conflictMessage = $this->conflictMessage($billboard, $userId, $startDate, $endDate)) {
            return ['ok' => false, 'status' => 409, 'message' => $conflictMessage];
        }

        $amounts = $this->pricing->calculate($billboard, $startDate, $endDate);
        $holdMinutes = (int) Setting::get('hold_minutes', 15);

        $booking = Booking::query()->create([
            'billboard_id' => $billboard->id,
            'user_id' => $userId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_amount' => $amounts['total'],
            'advance_amount' => $amounts['advance'],
            'status' => 'held',
            'expires_at' => now()->addMinutes($holdMinutes),
        ]);

        return [
            'ok' => true,
            'status' => 201,
            'message' => "Dates held for {$holdMinutes} minutes. Add your campaign details to continue.",
            'booking' => $booking,
        ];
    }


    private function conflictMessage(Billboard $billboard, int $userId, string $startDate, string $endDate): ?string
    {
        $billboard->bookings()
            ->where('user_id', $userId)
            ->where('status', 'held')
            ->delete();

        $conflict = $billboard->activeBookings()
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->first();

        if (! $conflict) {
            return null;
        }

        return $conflict->status === 'held'
            ? 'Someone else is currently holding these dates. Please try again in a few minutes.'
            : 'These dates conflict with an existing booking.';
    }

    /**
     * @param  array<string, mixed>  $data  SubmitCampaignRequest::validated(), minus 'creative'
     * @return array{ok: bool, status: int, message: string, booking?: Booking}
     */
    public function submitCampaign(Booking $booking, int $userId, array $data, UploadedFile $creative): array
    {
        if ($booking->user_id !== $userId) {
            return ['ok' => false, 'status' => 403, 'message' => 'Forbidden: this is not your booking hold.'];
        }

        // Current status must be held.
        if ($booking->status !== 'held') {
            return ['ok' => false, 'status' => 422, 'message' => 'This hold is no longer active.'];
        }

        if ($booking->expires_at && $booking->expires_at->isPast()) {
            return ['ok' => false, 'status' => 410, 'message' => 'Your hold has expired, please pick dates again.'];
        }

        $path = Storage::disk('public')->putFile('campaign-creatives', $creative);
        $holdMinutes = (int) Setting::get('hold_minutes', 15);

        
        $booking->update($data + [
            'creative_path' => $path,
            'status' => 'pending_payment',
            'expires_at' => now()->addMinutes($holdMinutes),
        ]);

        
        $commissionRate = (float) Setting::get('commission_rate', 10);
        $commission = round((float) $booking->total_amount * ($commissionRate / 100), 2);
        $ownerPayable = round((float) $booking->total_amount - $commission, 2);

        Payment::query()->create([
            'booking_id' => $booking->id,
            'amount' => $booking->advance_amount,
            'payment_type' => 'advance',
            'status' => 'pending',
            'commission_amount' => $commission,
            'owner_payable' => $ownerPayable,
        ]);

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Campaign details saved. Pay the advance to submit your request.',
            'booking' => $booking->fresh(['payments']),
        ];
    }
}
