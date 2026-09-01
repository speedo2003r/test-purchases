<?php

use App\Http\Controllers\Api\AdminPurchaseController;
use App\Http\Controllers\Api\PaymentAttemptController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/services/{service}/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
    Route::post('/purchases/{purchase}/payment-attempts', [PaymentAttemptController::class, 'store']);
});

Route::post('/webhooks/payments', PaymentWebhookController::class);

Route::middleware(['auth:sanctum', 'can:admin'])->prefix('admin')->group(function () {
    Route::get('/purchases', [AdminPurchaseController::class, 'index']);
    Route::get('/purchases/{purchase}', [AdminPurchaseController::class, 'show']);
});
