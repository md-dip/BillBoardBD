<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\Owner\OwnerLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Every payment collected on this owner's boards - what the "Revenue (BDT)"
     * tile on their dashboard is made of - and where each one's earnings have
     * got to since.
     *
     * The ledger itself lives in OwnerLedgerService, which the BillboardBD
     * Assistant also reads. Both surfaces quoting one implementation is the
     * whole point: an owner who asks the assistant what a board earned must be
     * told what this page would show them.
     */
    public function transactions(Request $request, OwnerLedgerService $ledger): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $ledger->forOwner($request->user()->id),
            'message' => null,
        ]);
    }
}
