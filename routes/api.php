<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BillboardController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/billboards/nearby', [BillboardController::class, 'nearby']);
Route::get('/billboards', [BillboardController::class, 'index']);