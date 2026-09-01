<?php

use App\Http\Controllers\Client\BookingController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\PaymentGatewayController;
use Illuminate\Support\Facades\Route;

// Client (advertiser) routes - require a valid Sanctum token.
Route::middleware('auth:sanctum')->group(function () {
    // Booking flow
    Route::post('/bookings/hold', [BookingController::class, 'hold']);                          // step 1
    Route::post('/bookings/{booking}/campaign', [BookingController::class, 'submitCampaign']);  // step 2
    Route::get('/bookings/my', [BookingController::class, 'myBookings']);                        // My Bookings
    Route::get('/bookings/{booking}/invoice', [BookingController::class, 'invoice']);            // advance/final invoice

    // Payment - real gateway checkout (client actor) + manual/offline record
    Route::post('/payments/{payment}/checkout', [PaymentGatewayController::class, 'checkout']); // step 3 - SSLCommerz
    Route::post('/payments/{payment}/pay', [PaymentController::class, 'pay']);                   // manual / offline
});
