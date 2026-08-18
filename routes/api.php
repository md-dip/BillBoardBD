<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BillboardController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Admin\BillboardController as AdminBillboardController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;

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

// Admin routes — require Sanctum token AND role=admin
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/ping', fn () => response()->json(['success' => true, 'data' => null, 'message' => 'admin ok']));

    // Billboard CRUD (index/store/update/destroy — no show, admin edits from the list)
    Route::apiResource('billboards', AdminBillboardController::class)->except(['show']);

    // Bookings review + approval workflow
    Route::get('/bookings', [AdminBookingController::class, 'index']);
    Route::patch('/bookings/{booking}/approve', [AdminBookingController::class, 'approve']);
    Route::patch('/bookings/{booking}/reject', [AdminBookingController::class, 'reject']);
    Route::post('/bookings/{booking}/balance-payment', [AdminPaymentController::class, 'recordBalance']);

    // Platform settings (commission %, advance %)
    Route::get('/settings', [AdminSettingController::class, 'index']);
    Route::put('/settings', [AdminSettingController::class, 'update']);

    // Reports (revenue + occupancy)
    Route::get('/reports/revenue', [AdminReportController::class, 'revenue']);
    Route::get('/reports/occupancy', [AdminReportController::class, 'occupancy']);
});