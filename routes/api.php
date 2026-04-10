<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ShiftKaryawanController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\StationController;

Route::prefix('v1')->group(function () {

    // ================= AUTH (PUBLIC) =================
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:5,1'); // Limit login attempts
    Route::post('/login-pin', [AuthController::class, 'loginPin'])->name('login.pin');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
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
        Route::put('/me', [AuthController::class, 'updateProfile'])->name('me.update');

        // ================= OUTLET =================
        Route::post('/outlets', [OutletController::class, 'createOutlet'])->name('outlets.create');
        Route::get('/outlets', [OutletController::class, 'index'])->name('outlets.index');
        Route::get('/outlets/{outlet}', [OutletController::class, 'show'])->name('outlets.show');
        Route::put('/outlets/{outlet}', [OutletController::class, 'update'])->name('outlets.update');
        Route::delete('/outlets/{outlet}', [OutletController::class, 'destroy'])->name('outlets.destroy');

        Route::get('/outlets/{outlet}/products', [OutletController::class, 'getProducts']);
        Route::post('/outlets/{outlet}/sync-products', [OutletController::class, 'syncProducts']);

        // ================= TABLE =================
        Route::apiResource('tables', TableController::class);

        // ================= USER (KARYAWAN) =================
        Route::prefix('users/karyawan')->group(function () {
            Route::post('/', [UserController::class, 'createKaryawan'])->name('users.karyawan.create');
            Route::get('/', [UserController::class, 'listKaryawan'])->name('users.karyawan.list');
            Route::get('/{karyawan}', [UserController::class, 'showKaryawan'])->name('users.karyawan.show');
            Route::put('/{karyawan}', [UserController::class, 'updateKaryawan'])->name('users.karyawan.update');
            Route::delete('/{karyawan}', [UserController::class, 'deleteKaryawan'])->name('users.karyawan.delete');
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

        // ================= STATION =================
        Route::apiResource('stations', StationController::class);
        Route::get('/stations/{id}/products', [StationController::class, 'products'])->name('stations.products');

        // ================= SHIFT KARYAWAN =================
        Route::apiResource('shift-karyawans', ShiftKaryawanController::class);


        // ================= ORDER =================
        Route::prefix('orders')->group(function () {

            Route::get('/', [OrderController::class, 'index']);
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/{id}', [OrderController::class, 'show']);

            // checkout order (create order + items)
            Route::post('/checkout', [OrderController::class, 'checkoutOrder']);

            // cart
            Route::post('/{id}/items', [OrderController::class, 'addItem']);
            Route::delete('/{id}/items/{itemId}', [OrderController::class, 'removeItem']);

            // payment
            Route::post('/{id}/checkout', [OrderController::class, 'checkout']);
            Route::post('/{id}/payments', [OrderController::class, 'pay']);
            Route::patch('/{id}/adjustments', [OrderController::class, 'updateAdjustments']);

        });

        // ================= ORDER ITEM (KITCHEN ACTION) =================
        Route::prefix('order-items')->group(function () {

            // update status (pending → cooking → done)
            Route::patch('/{id}/status', [OrderController::class, 'updateItemStatus']);

        });

    });
});
