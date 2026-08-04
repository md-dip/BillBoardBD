<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /**
     * Only the commission-safe values the client needs to render prices and
     * the hold countdown. The commission_rate itself is never exposed here.
     */
    public function public(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'advance_percentage' => (float) Setting::get('advance_percentage', 30),
                'hold_minutes' => (int) Setting::get('hold_minutes', 15),
            ],
            'message' => null,
        ]);
    }
}