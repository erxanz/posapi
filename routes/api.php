<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\API\TableController;
use App\Http\Controllers\StationController;

Route::prefix('v1')->group(function () {

    // ================= AUTH (PUBLIC) =================
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login-pin', [AuthController::class, 'loginPin'])->name('login.pin');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    // ================= PUBLIC API (QR) =================
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

        // ================= OUTLET =================
        Route::post('/outlets', [OutletController::class, 'createOutlet'])
            ->name('outlets.create');

        // ================= TABLE =================
        Route::apiResource('tables', TableController::class);

        // ================= USER (KARYAWAN) =================
        Route::prefix('users/karyawan')->group(function () {
            Route::post('/', [UserController::class, 'createKaryawan']);
            Route::get('/', [UserController::class, 'listKaryawan']);
            Route::get('/{id}', [UserController::class, 'showKaryawan']);
            Route::put('/{id}', [UserController::class, 'updateKaryawan']);
            Route::delete('/{id}', [UserController::class, 'deleteKaryawan']);
        });

        // ================= USER (DEVELOPER) =================
        Route::prefix('users')->group(function () {
            Route::post('/', [UserController::class, 'createUser']);
            Route::get('/', [UserController::class, 'listUsers']);
            Route::get('/{id}', [UserController::class, 'showUser']);
            Route::put('/{id}', [UserController::class, 'updateUser']);
            Route::delete('/{id}', [UserController::class, 'deleteUser']);
        });

        // ================= CATEGORY =================
        Route::apiResource('categories', CategoryController::class);

        // ================= PRODUCT =================
        Route::apiResource('products', ProductController::class);

        // ================= STATION =================
        Route::apiResource('stations', StationController::class);

        // ================= STATION EXTRA =================
        Route::prefix('stations')->group(function () {

            // produk per station (kitchen/bar menu)
            Route::get('/{id}/products', [StationController::class, 'products']);

            // 🔥 order per station (KITCHEN DISPLAY)
            Route::get('/{stationId}/orders', [OrderController::class, 'stationOrders']);

        });

        // ================= ORDER =================
        Route::prefix('orders')->group(function () {

            Route::get('/', [OrderController::class, 'index']);
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/{id}', [OrderController::class, 'show']);

            // cart
            Route::post('/{id}/items', [OrderController::class, 'addItem']);
            Route::delete('/{id}/items/{itemId}', [OrderController::class, 'removeItem']);

            // checkout
            Route::post('/{id}/checkout', [OrderController::class, 'checkout']);

        });

        // ================= ORDER ITEM (KITCHEN ACTION) =================
        Route::prefix('order-items')->group(function () {

            // update status (pending → cooking → done)
            Route::patch('/{id}/status', [OrderController::class, 'updateItemStatus']);

        });

    });
});
