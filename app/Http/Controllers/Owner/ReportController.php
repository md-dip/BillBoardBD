<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\Owner\OwnerPanelCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Every payment collected on this owner's boards - what the "Revenue (BDT)"
     * tile on their dashboard is made of - and where each one's earnings have
     * got to since.
     *
     * The actual calculation lives in Services\Owner\OwnerPanelCalculationService
     * - this method only shapes the HTTP response.
     */
    public function transactions(Request $request, OwnerPanelCalculationService $calculations): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $calculations->transactionsSummary($request->user()->id),
            'message' => null,
        ]);
    }
}
