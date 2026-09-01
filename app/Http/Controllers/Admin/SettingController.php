<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'commission_rate' => (float) Setting::get('commission_rate', 10),
                'advance_percentage' => (float) Setting::get('advance_percentage', 30),
                'final_payment_days' => (int) Setting::get('final_payment_days', 7),
            ],
            'message' => null,
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        Setting::query()->updateOrCreate(
            ['key' => 'commission_rate'],
            ['value' => (string) $request->validated('commission_rate')],
        );

        Setting::query()->updateOrCreate(
            ['key' => 'advance_percentage'],
            ['value' => (string) $request->validated('advance_percentage')],
        );

        Setting::query()->updateOrCreate(
            ['key' => 'final_payment_days'],
            ['value' => (string) $request->validated('final_payment_days')],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'commission_rate' => (float) $request->validated('commission_rate'),
                'advance_percentage' => (float) $request->validated('advance_percentage'),
                'final_payment_days' => (int) $request->validated('final_payment_days'),
            ],
            'message' => 'Settings updated',
        ]);
    }
}
