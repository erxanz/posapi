<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\StationController;

Route::prefix('v1')->group(function () {

    // ================= AUTH (PUBLIC) =================
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:5,1');
    Route::post('/login-pin', [AuthController::class, 'loginPin'])->name('login.pin');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    // ================= PUBLIC API (QR) =================
    // No auth required, customer dapat access menu & order
    Route::prefix('public')->group(function () {
        Route::get('/menu/{outletId}/{tableId}', [ProductController::class, 'publicMenu'])
            ->name('public.menu');

        Route::post('/order', [OrderController::class, 'publicOrder'])
            ->name('public.order');
    });

    // ================= PROTECTED (SANCTUM) =================
    Route::middleware('auth:sanctum')->group(function () {

        // ================= AUTH =================
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::put('/me', [AuthController::class, 'updateProfile'])->name('me.update');

        // ================= OUTLET (Manager/Developer) =================
        Route::middleware('check.outlet.access')->group(function () {
            Route::prefix('outlets')->group(function () {
                Route::post('/', [OutletController::class, 'store'])->name('outlets.store');
                Route::get('/', [OutletController::class, 'index'])->name('outlets.index');
                Route::get('/{outlet}', [OutletController::class, 'show'])->name('outlets.show');
                Route::put('/{outlet}', [OutletController::class, 'update'])->name('outlets.update');
                Route::delete('/{outlet}', [OutletController::class, 'destroy'])->name('outlets.destroy');
            });
        });

        // ================= USER MANAGEMENT =================
        // Karyawan CRUD (Manager)
        Route::prefix('users/karyawan')->group(function () {
            Route::post('/', [UserController::class, 'createKaryawan'])->name('users.karyawan.create');
            Route::get('/', [UserController::class, 'listKaryawan'])->name('users.karyawan.list');
            Route::get('/{karyawan}', [UserController::class, 'showKaryawan'])->name('users.karyawan.show');
            Route::put('/{karyawan}', [UserController::class, 'updateKaryawan'])->name('users.karyawan.update');
            Route::delete('/{karyawan}', [UserController::class, 'deleteKaryawan'])->name('users.karyawan.delete');
        });

        // Developer User Management
        Route::prefix('users')->group(function () {
            Route::post('/', [UserController::class, 'createUser'])->name('users.create');
            Route::get('/', [UserController::class, 'listUsers'])->name('users.list');
            Route::get('/{user}', [UserController::class, 'showUser'])->name('users.show');
            Route::put('/{user}', [UserController::class, 'updateUser'])->name('users.update');
            Route::delete('/{user}', [UserController::class, 'deleteUser'])->name('users.delete');
        });

        // ================= TABLE =================
        Route::middleware('check.outlet.access')->group(function () {
            Route::apiResource('tables', TableController::class);
        });

        // ================= CATEGORY =================
        Route::middleware('check.outlet.access')->group(function () {
            Route::apiResource('categories', CategoryController::class);
        });

        // ================= PRODUCT =================
        Route::middleware('check.outlet.access')->group(function () {
            Route::apiResource('products', ProductController::class);
        });

        // ================= STATION =================
        Route::middleware('check.outlet.access')->group(function () {
            Route::apiResource('stations', StationController::class);
        });

        // ================= ORDER =================
        Route::middleware('check.outlet.access')->group(function () {
            Route::prefix('orders')->group(function () {
                // Standard CRUD
                Route::get('/', [OrderController::class, 'index'])->name('orders.index');
                Route::post('/', [OrderController::class, 'store'])->name('orders.store');
                Route::get('/{order}', [OrderController::class, 'show'])->name('orders.show');

                // Order management
                Route::post('/{order}/items', [OrderController::class, 'addItem'])->name('orders.addItem');
                Route::delete('/{order}/items/{item}', [OrderController::class, 'removeItem'])->name('orders.removeItem');
                Route::post('/{order}/checkout', [OrderController::class, 'checkout'])->name('orders.checkout');
                Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

                // Kitchen display
                Route::patch('/items/{item}/status', [OrderController::class, 'updateItemStatus'])->name('orders.updateItemStatus');
            });
        });

    }); // END Protected routes

}); // END v1 prefix
