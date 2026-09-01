<?php

use App\Http\Controllers\Shared\AuthController;
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
