<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BillboardController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingController;

// Public auth routes — no token needed
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/settings/public', [SettingController::class, 'public']);

// Public billboard routes — anyone can browse
Route::get('/billboards/nearby', [BillboardController::class, 'nearby']);
Route::get('/billboards', [BillboardController::class, 'index']);
Route::get('/billboards/{billboard}', [BillboardController::class, 'show']);

// Protected routes — require valid Sanctum token
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    // Booking flow
    Route::post('/bookings/hold', [BookingController::class, 'hold']);                          // step 1
    Route::post('/bookings/{booking}/campaign', [BookingController::class, 'submitCampaign']);  // step 2
    Route::get('/bookings/my', [BookingController::class, 'myBookings']);                        // My Bookings

    // Mock payment gateway
    Route::post('/payments/{payment}/pay', [PaymentController::class, 'pay']);                   // step 3
});