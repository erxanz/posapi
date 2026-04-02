<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\API\TableController;

Route::prefix('v1')->group(function () {

    // ================= AUTH (PUBLIC) =================
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login-pin', [AuthController::class, 'loginPin'])->name('login.pin');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    // ================= PUBLIC API (QR) =================
    Route::prefix('public')->group(function () {

        // ambil menu dari QR (outlet + meja)
        Route::get('/menu/{outletId}/{tableId}', [ProductController::class, 'publicMenu'])
            ->name('public.menu');

        // customer buat order tanpa login
        Route::post('/order', [OrderController::class, 'publicOrder'])
            ->name('public.order');

    });

    // ================= PROTECTED (SANCTUM) =============
    Route::middleware('auth:sanctum')->group(function () {

        // ================= AUTH =================
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        // ================= OUTLET =================
        Route::post('/outlets', [OutletController::class, 'createOutlet'])
            ->name('outlets.create');

        // ================= TABLE (MEJA) =================
        Route::apiResource('tables', TableController::class);

        // ================= USER (KARYAWAN) =================
        Route::prefix('users/karyawan')->group(function () {
            Route::post('/', [UserController::class, 'createKaryawan'])->name('users.karyawan.create');
            Route::get('/', [UserController::class, 'listKaryawan'])->name('users.karyawan.list');
            Route::get('/{id}', [UserController::class, 'showKaryawan'])->name('users.karyawan.show');
            Route::put('/{id}', [UserController::class, 'updateKaryawan'])->name('users.karyawan.update');
            Route::delete('/{id}', [UserController::class, 'deleteKaryawan'])->name('users.karyawan.delete');
        });

        // ================= USER (DEVELOPER) =================
        Route::prefix('users')->group(function () {
            Route::post('/', [UserController::class, 'createUser'])->name('users.create');
            Route::get('/', [UserController::class, 'listUsers'])->name('users.list');
            Route::get('/{id}', [UserController::class, 'showUser'])->name('users.show');
            Route::put('/{id}', [UserController::class, 'updateUser'])->name('users.update');
            Route::delete('/{id}', [UserController::class, 'deleteUser'])->name('users.delete');
        });

        // ================= CATEGORY =================
        Route::apiResource('categories', CategoryController::class);

        // ================= PRODUCT =================
        Route::apiResource('products', ProductController::class);

        // ================= ORDER (POS INTERNAL) =================
        Route::prefix('orders')->group(function () {

            Route::get('/', [OrderController::class, 'index'])->name('orders.index');
            Route::post('/', [OrderController::class, 'store'])->name('orders.store');
            Route::get('/{id}', [OrderController::class, 'show'])->name('orders.show');

            // cart
            Route::post('/{id}/items', [OrderController::class, 'addItem'])->name('orders.addItem');
            Route::delete('/{id}/items/{itemId}', [OrderController::class, 'removeItem'])->name('orders.removeItem');

            // checkout kasir
            Route::post('/{id}/checkout', [OrderController::class, 'checkout'])->name('orders.checkout');

        });

    });
});
