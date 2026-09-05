<?php

use App\Http\Controllers\Client\PaymentGatewayController;
use App\Http\Controllers\Owner\ListingPaymentController;
use App\Http\Controllers\Shared\AuthController;
use App\Http\Controllers\Shared\BillboardController;
use App\Http\Controllers\Shared\SettingController;
use Illuminate\Support\Facades\Route;

// Public auth routes - no token needed
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Password reset - both are hit by a logged-out visitor, so no token. The
// second one's safety is the emailed single-use token, checked by Laravel's
// password broker against the password_reset_tokens table.
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/settings/public', [SettingController::class, 'public']);

// Public billboard routes - anyone can browse
Route::get('/billboards/nearby', [BillboardController::class, 'nearby']);
Route::get('/billboards', [BillboardController::class, 'index']);
Route::get('/billboards/{billboard}', [BillboardController::class, 'show']);

// SSLCommerz callbacks - hit by the customer's browser / SSLCommerz servers,
// so no token and no CSRF. Safety is the unguessable tran_id + a server-side
// re-validation against SSLCommerz inside the controller.
Route::post('/payments/sslcommerz/success', [PaymentGatewayController::class, 'success']);
Route::post('/payments/sslcommerz/fail', [PaymentGatewayController::class, 'fail']);
Route::post('/payments/sslcommerz/cancel', [PaymentGatewayController::class, 'cancel']);
Route::post('/payments/sslcommerz/ipn', [PaymentGatewayController::class, 'ipn']);

// Owner board-listing-fee SSLCommerz callbacks - same rationale as above.
Route::post('/listing-payments/sslcommerz/success', [ListingPaymentController::class, 'success']);
Route::post('/listing-payments/sslcommerz/fail', [ListingPaymentController::class, 'fail']);
Route::post('/listing-payments/sslcommerz/cancel', [ListingPaymentController::class, 'cancel']);
Route::post('/listing-payments/sslcommerz/ipn', [ListingPaymentController::class, 'ipn']);
