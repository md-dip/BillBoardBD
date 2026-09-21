<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class NotFoundController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Not found.',
        ], 404);
    }
}
