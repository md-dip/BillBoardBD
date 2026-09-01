<?php

use App\Http\Controllers\Client\PaymentGatewayController;
use App\Http\Controllers\Shared\AuthController;
use App\Http\Controllers\Shared\BillboardController;
use App\Http\Controllers\Shared\SettingController;
use Illuminate\Support\Facades\Route;

// Public auth routes - no token needed
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
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
