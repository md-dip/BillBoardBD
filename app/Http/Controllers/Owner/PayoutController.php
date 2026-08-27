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
            ],
            'message' => null,
        ]);
    }
}
