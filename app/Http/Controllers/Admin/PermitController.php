<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PermitController extends Controller
{
    /**
     * Permit compliance for every live board: days left until permit_expiry_date,
     * the three KPI counts, and the sorted table row list - all computed here so
     * the frontend only renders. Previously this math (and the sort) lived in
     * the Permits.jsx component itself.
     */
    public function index(): JsonResponse
    {
        $today = Carbon::today();

        $billboards = Billboard::query()
            ->where('listing_status', 'approved')
            ->whereNotNull('permit_expiry_date')
            ->with('owner')
            ->orderBy('id')
            ->get()
            ->map(function (Billboard $billboard) use ($today) {
                return [
                    'id' => $billboard->id,
                    'title' => $billboard->title,
                    'address' => $billboard->address,
                    'owner' => $billboard->owner ? ['id' => $billboard->owner->id, 'name' => $billboard->owner->name] : null,
                    'permit_expiry_date' => $billboard->permit_expiry_date->format('Y-m-d'),
                    'days_left' => (int) round(($billboard->permit_expiry_date->timestamp - $today->timestamp) / 86400),
                ];
            });

        // Soonest-to-expire first.
        $sorted = $billboards->sortBy('days_left')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'expired' => $sorted->where('days_left', '<', 0)->count(),
                'expiring_soon' => $sorted->whereBetween('days_left', [0, 30])->count(),
                'compliant' => $sorted->where('days_left', '>', 30)->count(),
                'billboards' => $sorted,
            ],
            'message' => null,
        ]);
    }
}
