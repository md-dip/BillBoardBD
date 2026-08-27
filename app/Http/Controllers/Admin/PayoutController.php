<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'outstanding' => $this->payouts->outstandingByOwner()->map(fn (array $row) => [
                    'owner' => $row['owner'],
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
            'method' => ['nullable', 'in:bkash,nagad,bank,cash'],
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
}
