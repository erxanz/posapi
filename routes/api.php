<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OutletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ================= AUTH (PUBLIC) =================
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login-pin', [AuthController::class, 'loginPin'])->name('login.pin');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    // ================= PROTECTED =================
    Route::middleware('auth:sanctum')->group(function () {

        // AUTH
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        // OUTLET
        Route::post('/outlets', [OutletController::class, 'createOutlet'])->name('outlets.create');

        // USER / KARYAWAN
        Route::post('/karyawan', [UserController::class, 'createKaryawan'])->name('karyawan.create');
        Route::get('/karyawan', [UserController::class, 'listKaryawan'])->name('karyawan.list');
        Route::delete('/karyawan/{id}', [UserController::class, 'deleteKaryawan'])->name('karyawan.delete');

        // CATEGORY & PRODUCT (WAJIB LOGIN)
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('products', ProductController::class);

        // ORDER (POS)
        Route::apiResource('orders', OrderController::class);
        Route::post('/orders/{id}/items', [OrderController::class, 'addItem'])->name('orders.addItem');
        Route::delete('/orders/{id}/items/{itemId}', [OrderController::class, 'removeItem'])->name('orders.removeItem');
        Route::post('/orders/{id}/checkout', [OrderController::class, 'checkout'])->name('orders.checkout');
    });
});
