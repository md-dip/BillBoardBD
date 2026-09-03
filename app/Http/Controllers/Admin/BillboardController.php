<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectListingRequest;
use App\Http\Requests\Shared\StoreBillboardRequest;
use App\Http\Requests\Shared\UpdateBillboardRequest;
use App\Models\Billboard;
use App\Services\Admin\ListingApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillboardController extends Controller
{
    public function __construct(private readonly ListingApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $query = Billboard::query()
            ->with(['owner', 'reviewer', 'listingPayments'])
            ->orderBy('id');

        if ($listingStatus = $request->query('listing_status')) {
            $query->where('listing_status', $listingStatus);
        }

        // paginate() keeps the response shape (data.data) the frontend expects.
        // The page size sits well above the seeded inventory so newly listed
        // boards (which always get the highest ids) never fall off page one -
        // the admin table, the Listing-requests tab and the dashboard counts
        // all read this endpoint.
        return response()->json([
            'success' => true,
            'data' => $query->paginate(1000),
            'message' => null,
        ]);
    }

    public function store(StoreBillboardRequest $request): JsonResponse
    {
        // Admin-created boards skip the owner listing flow - live immediately.
        $billboard = Billboard::query()->create([
            ...$request->validated(),
            'listing_status' => 'approved',
        ]);

        return response()->json([
            'success' => true,
            'data' => $billboard,
            'message' => 'Billboard created',
        ], 201);
    }

    public function update(UpdateBillboardRequest $request, Billboard $billboard): JsonResponse
    {
        $billboard->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $billboard->fresh(),
            'message' => 'Billboard updated',
        ]);
    }

    public function destroy(Billboard $billboard): JsonResponse
    {
        $billboard->delete();

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Billboard deleted',
        ]);
    }

    /** Approve an owner-submitted board → it goes live on the public map/list. */
    public function approve(Request $request, Billboard $billboard): JsonResponse
    {
        $result = $this->approvals->approve($billboard, $request->user());

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['billboard'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }

    /** Reject an owner-submitted board → the listing fee is auto refunded. */
    public function reject(RejectListingRequest $request, Billboard $billboard): JsonResponse
    {
        $result = $this->approvals->reject($billboard, $request->validated('rejection_reason'), $request->user());

        return response()->json([
            'success' => $result['ok'],
            'data' => $result['billboard'] ?? null,
            'message' => $result['message'],
        ], $result['status']);
    }
}
