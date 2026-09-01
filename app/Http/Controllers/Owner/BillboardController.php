<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreBillboardRequest;
use App\Http\Requests\Shared\UpdateBillboardRequest;
use App\Models\Billboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Billboard::query()
                ->where('owner_id', $request->user()->id)
                ->orderBy('id')
                ->paginate(20),
            'message' => null,
        ]);
    }

    public function store(StoreBillboardRequest $request): JsonResponse
    {
        $billboard = Billboard::query()->create([
            ...$request->validated(),
            'owner_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $billboard,
            'message' => 'Billboard listed',
        ], 201);
    }

    public function update(UpdateBillboardRequest $request, Billboard $billboard): JsonResponse
    {
        if ($billboard->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this billboard belongs to another owner.',
            ], 403);
        }

        $billboard->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $billboard->fresh(),
            'message' => 'Billboard updated',
        ]);
    }

    public function destroy(Request $request, Billboard $billboard): JsonResponse
    {
        if ($billboard->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this billboard belongs to another owner.',
            ], 403);
        }

        $billboard->delete();

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Billboard deleted',
        ]);
    }
}
