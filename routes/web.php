<?php

use App\Http\Controllers\Admin\PurchaseController as AdminPurchaseWebController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.purchases.index');
});

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/purchases', [AdminPurchaseWebController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/{purchase}', [AdminPurchaseWebController::class, 'show'])->name('purchases.show');
});
