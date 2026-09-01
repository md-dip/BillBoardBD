<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'outstanding' => $this->payouts->outstandingForOwner($owner),
                'history' => $this->payouts->history($owner),
                'payout_details' => $owner->only([
                    'payout_method', 'payout_account_name', 'payout_account_number',
                    'payout_bank_name', 'payout_branch',
                ]),
            ],
            'message' => null,
        ]);
    }

    /**
     * The owner sets up where their payouts should be sent - read by
     * admin (via the owner record already returned on the Admin Payouts
     * page) before they manually trigger a payout.
     */
    public function updateDetails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payout_method' => ['required', 'in:bkash,nagad,bank'],
            'payout_account_name' => ['required', 'string', 'max:120'],
            'payout_account_number' => ['required', 'string', 'max:60'],
            'payout_bank_name' => ['required_if:payout_method,bank', 'nullable', 'string', 'max:120'],
            'payout_branch' => ['nullable', 'string', 'max:120'],
        ]);

        // bKash/Nagad accounts have no bank name or branch - don't leave
        // stale values behind from a previous "bank" selection.
        if ($validated['payout_method'] !== 'bank') {
            $validated['payout_bank_name'] = null;
            $validated['payout_branch'] = null;
        }

        $owner = $request->user();
        $owner->update($validated);

        return response()->json([
            'success' => true,
            'data' => $owner->only([
                'payout_method', 'payout_account_name', 'payout_account_number',
                'payout_bank_name', 'payout_branch',
            ]),
            'message' => 'Payout details saved.',
        ]);
    }
}
