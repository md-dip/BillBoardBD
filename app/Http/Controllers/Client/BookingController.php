<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\HoldBookingRequest;
use App\Http\Requests\Client\SubmitCampaignRequest;
use App\Models\Billboard;
use App\Models\Booking;
use App\Services\Client\BookingLifecycleService;
use App\Services\Shared\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingLifecycleService $lifecycle,
        private readonly InvoiceService $invoices,
    ) {}

    /** Step 1: pick dates, lock the slot for hold_minutes while the user fills in the rest. */
    public function hold(HoldBookingRequest $request): JsonResponse
    {
        $billboard = Billboard::query()
            ->where('listing_status', 'approved')
            ->findOrFail($request->validated('billboard_id'));

        $result = $this->lifecycle->hold(
            $billboard,
            $request->user()->id,
            $request->validated('start_date'),
            $request->validated('end_date'),
        );

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }

    /** Step 2: campaign details + creative, required before payment. */
    public function submitCampaign(SubmitCampaignRequest $request, Booking $booking): JsonResponse
    {
        $data = $request->validated();
        unset($data['creative']);

        $result = $this->lifecycle->submitCampaign($booking, $request->user()->id, $data, $request->file('creative'));

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['booking'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }

    /**
     * Everything  order, so it's the only column that sorts this reliably.
     */
    public function myBookings(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->where('status', '!=', 'held')
            ->with(['billboard', 'payments', 'proofOfPostings', 'invoices'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings,
            'message' => null,
        ]);
    }

    /**
     * The client's invoice for a booking - the final invoice once it exists,
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
