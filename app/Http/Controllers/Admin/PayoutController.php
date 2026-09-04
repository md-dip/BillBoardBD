<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\User;
use App\Services\Shared\PayoutReceiptService;
use App\Services\Shared\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly PayoutReceiptService $receipts,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                // Map to an explicit owner payload rather than dumping the whole
                // User model - the admin needs the payout account to know where
                // to send the money, so surface exactly those fields, nothing more.
                'outstanding' => $this->payouts->outstandingByOwner()->map(fn (array $row) => [
                    'owner' => [
                        'id' => $row['owner']->id,
                        'name' => $row['owner']->name,
                        'email' => $row['owner']->email,
                        'payout_method' => $row['owner']->payout_method,
                        'payout_account_name' => $row['owner']->payout_account_name,
                        'payout_account_number' => $row['owner']->payout_account_number,
                        'payout_bank_name' => $row['owner']->payout_bank_name,
                        'payout_branch' => $row['owner']->payout_branch,
                    ],
                    'amount' => $row['amount'],
                ]),
                'history' => $this->payouts->history(),
            ],
            'message' => null,
        ]);
    }

    public function store(Request $request, User $owner): JsonResponse
    {
        if ($owner->role !== 'owner') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This user is not a billboard owner.',
            ], 422);
        }

        $outstanding = $this->payouts->outstandingForOwner($owner);
        if ($outstanding <= 0) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This owner has no outstanding balance to pay out.',
            ], 422);
        }

        $validated = $request->validate([
            'method' => ['nullable', 'in:bkash,nagad,bank'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $payout = $this->payouts->payout($owner, $validated);

        return response()->json([
            'success' => true,
            'data' => $payout,
            'message' => 'Payout recorded',
        ], 201);
    }

    /**
     * The printable receipt for a recorded payout - the admin sees the full
     * statement (same payload the owner gets).
     */
    public function receipt(Payout $payout): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->receipts->payload($payout),
            'message' => null,
        ]);
    }
}
