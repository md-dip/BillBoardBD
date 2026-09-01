<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreBillboardRequest;
use App\Http\Requests\Shared\UpdateBillboardRequest;
use App\Models\Billboard;
use Illuminate\Http\JsonResponse;

class BillboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Billboard::query()->with('owner')->orderBy('id')->paginate(100),
            'message' => null,
        ]);
    }

    public function store(StoreBillboardRequest $request): JsonResponse
    {
        $billboard = Billboard::query()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $billboard,
            'message' => 'Billboard created',
        ], 201);
    }

    public function update(UpdateBillboardRequest $request, Billboard $billboard): JsonResponse
    {
        $billboard->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $billboard->fresh(),
            'message' => 'Billboard updated',
        ]);
    }

    public function destroy(Billboard $billboard): JsonResponse
    {
        $billboard->delete();

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Billboard deleted',
        ]);
    }
}