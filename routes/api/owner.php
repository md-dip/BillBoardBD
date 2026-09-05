<?php

use App\Http\Controllers\Owner\BillboardController as OwnerBillboardController;
use App\Http\Controllers\Owner\BookingController as OwnerBookingController;
use App\Http\Controllers\Owner\ListingPaymentController as OwnerListingPaymentController;
use App\Http\Controllers\Owner\PayoutController as OwnerPayoutController;
use App\Http\Controllers\Owner\ReportController as OwnerReportController;
use App\Http\Controllers\Owner\ProofOfPostingController as OwnerProofOfPostingController;
use Illuminate\Support\Facades\Route;

// Owner routes - require Sanctum token AND role=owner
Route::middleware(['auth:sanctum', 'role:owner'])->prefix('owner')->group(function () {
    // Billboard CRUD, scoped to the logged-in owner's own listings
    Route::apiResource('billboards', OwnerBillboardController::class)->except(['show']);

    // One-time board listing fee - SSLCommerz checkout (callbacks are public, see api/public.php)
    Route::post('/listing-payments/{listingPayment}/checkout', [OwnerListingPaymentController::class, 'checkout']);

    // Booking requests for the owner's billboards + approval workflow
    Route::get('/bookings', [OwnerBookingController::class, 'index']);
    Route::patch('/bookings/{booking}/approve', [OwnerBookingController::class, 'approve']);
    Route::patch('/bookings/{booking}/reject', [OwnerBookingController::class, 'reject']);

    // Stage 5: upload proof of posting
    Route::post('/bookings/{booking}/proof', [OwnerProofOfPostingController::class, 'store']);

    // The transactions behind the dashboard's "Revenue (BDT)" tile
    Route::get('/reports/transactions', [OwnerReportController::class, 'transactions']);

    // Payouts (read-only history/outstanding) + the owner's own payout details
    Route::get('/payouts', [OwnerPayoutController::class, 'index']);
    Route::get('/payouts/{payout}/receipt', [OwnerPayoutController::class, 'receipt']);
    Route::put('/payout-details', [OwnerPayoutController::class, 'updateDetails']);
});
