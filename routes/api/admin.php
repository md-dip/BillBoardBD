<?php

use App\Http\Controllers\Admin\BillboardController as AdminBillboardController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\ProofOfPostingController as AdminProofOfPostingController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use Illuminate\Support\Facades\Route;

// Admin routes - require Sanctum token AND role=admin
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/ping', fn () => response()->json(['success' => true, 'data' => null, 'message' => 'admin ok']));

    // Billboard CRUD (index/store/update/destroy - no show, admin edits from the list)
    Route::apiResource('billboards', AdminBillboardController::class)->except(['show']);

    // Bookings review + approval workflow
    Route::get('/bookings', [AdminBookingController::class, 'index']);
    Route::get('/bookings/{booking}/invoice', [AdminBookingController::class, 'invoice']);
    Route::patch('/bookings/{booking}/approve', [AdminBookingController::class, 'approve']);
    Route::patch('/bookings/{booking}/reject', [AdminBookingController::class, 'reject']);
    Route::post('/bookings/{booking}/balance-payment', [AdminPaymentController::class, 'recordBalance']);

    // Stage 5: proof-of-posting verification
    Route::patch('/bookings/{booking}/proof/verify', [AdminProofOfPostingController::class, 'verify']);
    Route::patch('/bookings/{booking}/proof/reject', [AdminProofOfPostingController::class, 'reject']);

    // Platform settings (commission %, advance %, final payment window)
    Route::get('/settings', [AdminSettingController::class, 'index']);
    Route::put('/settings', [AdminSettingController::class, 'update']);

    // Reports (revenue + occupancy)
    Route::get('/reports/revenue', [AdminReportController::class, 'revenue']);
    Route::get('/reports/occupancy', [AdminReportController::class, 'occupancy']);

    // Owner payouts
    Route::get('/payouts', [AdminPayoutController::class, 'index']);
    Route::post('/payouts/{owner}', [AdminPayoutController::class, 'store']);
});
