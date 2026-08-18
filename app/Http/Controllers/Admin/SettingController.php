<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
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

        return response()->json([
            'success' => true,
            'data' => [
                'commission_rate' => (float) $request->validated('commission_rate'),
                'advance_percentage' => (float) $request->validated('advance_percentage'),
            ],
            'message' => 'Settings updated',
        ]);
    }
}