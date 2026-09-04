<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StoreBillboardListingRequest;
use App\Http\Requests\Shared\UpdateBillboardRequest;
use App\Models\Billboard;
use App\Services\Owner\ListingSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillboardController extends Controller
{
    public function __construct(private readonly ListingSubmissionService $submissions) {}

    public function index(Request $request): JsonResponse
    {
        // paginate() keeps the response shape (data.data) the frontend expects,
        // with the page size well above any one owner's inventory - same call
        // as AdminBillboardController. Nothing in the app renders pagination
        // controls, so a smaller page silently hides boards: the owner's My
        // Billboards table and the dashboard's "My billboards" count both read
        // this endpoint and would report only the first page.
        return response()->json([
            'success' => true,
            'data' => Billboard::query()
                ->where('owner_id', $request->user()->id)
                ->with('listingPayments')
                ->orderBy('id')
                ->paginate(1000),
            'message' => null,
        ]);
    }

    /**
     * List a new board. It is created straight away as `pending_payment` with a
     * pending listing-fee payment; the owner then checks out through SSLCommerz
     * (see ListingPaymentController) and only after the fee clears does it reach
     * admin review.
     */
    public function store(StoreBillboardListingRequest $request): JsonResponse
    {
        $result = $this->submissions->submit(
            $request->user(),
            $request->safe()->except(['photo', 'permit_document']),
            $request->file('photo'),
            $request->file('permit_document'),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'billboard' => $result['billboard'],
                'listing_payment' => $result['listing_payment'],
            ],
            'message' => 'Board saved. Pay the one-time listing fee to submit it for review.',
        ], 201);
    }

    public function update(UpdateBillboardRequest $request, Billboard $billboard): JsonResponse
    {
        if ($billboard->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this billboard belongs to another owner.',
            ], 403);
        }

        $billboard->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $billboard->fresh(),
            'message' => 'Billboard updated',
        ]);
    }

    public function destroy(Request $request, Billboard $billboard): JsonResponse
    {
        if ($billboard->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this billboard belongs to another owner.',
            ], 403);
        }

        $billboard->delete();

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Billboard deleted',
        ]);
    }
}
