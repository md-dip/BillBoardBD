<?php

namespace App\Http\Controllers;

use App\Http\Requests\HoldBookingRequest;
use App\Http\Requests\SubmitCampaignRequest;
use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\BookingPricingService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingPricingService $pricing,
        private readonly InvoiceService $invoices,
    ) {
    }

    /** Step 1: pick dates, lock the slot for hold_minutes while the user fills in the rest. */
    public function hold(HoldBookingRequest $request): JsonResponse
    {
        $billboard = Billboard::query()->findOrFail($request->validated('billboard_id'));
        $startDate = $request->validated('start_date');
        $endDate = $request->validated('end_date');

        // Drop this user's own stale holds on this billboard first, so
        // re-picking dates doesn't leave orphaned rows behind.
        $billboard->bookings()
            ->where('user_id', $request->user()->id)
            ->where('status', 'held')
            ->delete();

        // Any booking still "in flight" on overlapping dates blocks this one.
        $conflicts = $billboard->activeBookings()
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->exists();

        if ($conflicts) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'These dates conflict with an existing booking.',
            ], 409);
        }

        // Money is computed server-side — never trusted from the browser.
        $amounts = $this->pricing->calculate($billboard, $startDate, $endDate);
        $holdMinutes = (int) Setting::get('hold_minutes', 15);

        $booking = Booking::query()->create([
            'billboard_id' => $billboard->id,
            'user_id' => $request->user()->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_amount' => $amounts['total'],
            'advance_amount' => $amounts['advance'],
            'status' => 'held',
            'expires_at' => now()->addMinutes($holdMinutes),
        ]);

        return response()->json([
            'success' => true,
            'data' => $booking,
            'message' => "Dates held for {$holdMinutes} minutes. Add your campaign details to continue.",
        ], 201);
    }

    /** Step 2: campaign details + creative, required before payment. */
    public function submitCampaign(SubmitCampaignRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this is not your booking hold.',
            ], 403);
        }

        if ($booking->status !== 'held') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This hold is no longer active.',
            ], 422);
        }

        if ($booking->expires_at && $booking->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Your hold has expired, please pick dates again.',
            ], 410);
        }

        // Store the creative on the public disk (needs `php artisan storage:link`).
        $path = Storage::disk('public')->putFile('campaign-creatives', $request->file('creative'));
        $holdMinutes = (int) Setting::get('hold_minutes', 15);

        $booking->update([
            'brand_name' => $request->validated('brand_name'),
            'ad_category' => $request->validated('ad_category'),
            'campaign_description' => $request->validated('campaign_description'),
            'creative_path' => $path,
            'status' => 'pending_payment',
            'expires_at' => now()->addMinutes($holdMinutes),
        ]);

        // Commission is taken against the full total and frozen onto the
        // payment row (read from settings, never hard-coded).
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

        return response()->json([
            'success' => true,
            'data' => $booking->fresh(['payments']),
            'message' => 'Campaign details saved. Pay the advance to submit your request.',
        ]);
    }

    /** Everything this user has booked (holds excluded), newest first. */
    public function myBookings(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->where('status', '!=', 'held')
            ->with(['billboard', 'payments', 'proofOfPostings', 'invoices'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings,
            'message' => null,
        ]);
    }

    /**
     * The client's invoice for a booking — the final invoice once it exists,
     * otherwise the advance one. Never exposes the platform commission split.
     */
    public function invoice(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this is not your booking.',
            ], 403);
        }

        $invoice = $booking->invoices()
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')          // tiebreaker: the 'final' row is always the newer one
            ->first();

        if (! $invoice) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'No invoice yet. It is generated once the advance is paid.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->invoices->payload($invoice, showOwnerSplit: false),
            'message' => null,
        ]);
    }
}
