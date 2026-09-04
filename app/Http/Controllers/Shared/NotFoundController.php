<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class NotFoundController extends Controller
{
    /**
     * Catch-all for any /api/* URL that matches no route. Shared, because an
     * unknown endpoint is the same answer whichever actor asks for it.
     *
     * Without this Laravel throws NotFoundHttpException, which renders as
     * {"message": ""} - a body the SPA can neither read nor display. Routing
     * the miss through here keeps unknown endpoints on the exact same
     * success/data/message envelope every other endpoint returns.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Not found.',
        ], 404);
    }
}
