<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * Only the commission-safe values the SPA needs before auth: prices, the
     * hold countdown, and the board listing fee shown on the owner form. The
     * commission_rate itself is never exposed here.
     */
    public function public(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'advance_percentage' => (float) Setting::get('advance_percentage', 30),
                'hold_minutes' => (int) Setting::get('hold_minutes', 15),
                'listing_fee' => (float) Setting::get('listing_fee', 5000),
            ],
            'message' => null,
        ]);
    }
}
