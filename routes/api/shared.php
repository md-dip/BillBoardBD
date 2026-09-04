<?php

use App\Http\Controllers\Shared\AuthController;
use App\Http\Controllers\Shared\NotFoundController;
use App\Http\Controllers\Shared\NotificationController;
use Illuminate\Support\Facades\Route;

// Signed-in routes shared by all 3 actors - require a valid Sanctum token.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Notifications (shared by all 3 actors)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

// Any /api/* URL that matched none of the routes in this folder. ->fallback()
// is what makes it safe to declare here: Laravel sorts fallback routes behind
// every real route when matching, so it can't shadow client.php / admin.php /
// owner.php even though those are required after this file.
//
// Route::fallback() itself is GET-only, which would leave a POST/PATCH/DELETE
// to an unknown URL answering 405 with an empty body, so the verbs are spelled
// out here instead. OPTIONS is left off deliberately - that is the CORS
// preflight, and it must keep its normal handling.
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '{fallbackPlaceholder}', NotFoundController::class)
    ->where('fallbackPlaceholder', '.*')
    ->fallback();
