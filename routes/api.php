<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\HistoryTransactionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ShiftKaryawanController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    // ================= AUTH (PUBLIC) =================
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login')->middleware('throttle:5,1'); // Limit login attempts
    Route::post('/login-pin', [AuthController::class, 'loginPin'])->name('login.pin');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    // ================= MIDTRANS WEBHOOK (PUBLIC) =================
    Route::post('/midtrans/callback', [OrderController::class, 'midtransCallback'])->name('midtrans.callback');

    // ================= PUBLIC API (QR) =================
    Route::prefix('public')->group(function () {

        // Route::get('/menu/{outletId}/{tableId}', [ProductController::class, 'publicMenu'])->name('public.menu');
        Route::get('/menu/{token}', [ProductController::class, 'publicMenu'])->name('public.menu');

        Route::post('/order', [OrderController::class, 'publicOrder'])
            ->name('public.order');

    });

    // ================= PROTECTED (SANCTUM) =================
    Route::middleware('auth:sanctum')->group(function () {

        // ================= STOK (INVENTORY) =================
        Route::post('/stocks/adjust', [\App\Http\Controllers\StockController::class, 'adjust'])->name('stocks.adjust');

        // ================= STOCK CRUD =================
        Route::apiResource('stocks', \App\Http\Controllers\StockController::class);

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

        // ================= USER (DEVELOPER) =================
        Route::prefix('users')->group(function () {
            Route::post('/', [UserController::class, 'createUser'])->name('users.create');
            Route::get('/', [UserController::class, 'listUsers'])->name('users.list');
            Route::get('/{id}', [UserController::class, 'showUser'])->name('users.show');
            Route::put('/{id}', [UserController::class, 'updateUser'])->name('users.update');
            Route::delete('/{id}', [UserController::class, 'deleteUser'])->name('users.delete');
        });

        // ================= TABLE =================
        Route::apiResource('tables', TableController::class);

        // ================= CATEGORY =================
        Route::apiResource('categories', CategoryController::class);

        // ================= PRODUCT =================
        Route::apiResource('products', ProductController::class);

        Route::apiResource('discounts', DiscountController::class);
        Route::apiResource('taxes', TaxController::class);

        // ================= STATION =================
        Route::apiResource('stations', StationController::class);
        Route::get('/stations/{id}/products', [StationController::class, 'products'])->name('stations.products');

        // ================= SHIFT KARYAWAN =================
        // Khusus untuk Flutter (Action Kasir)
        Route::post('shift-karyawans/start', [ShiftKaryawanController::class, 'startShift']);
        Route::post('shift-karyawans/end', [ShiftKaryawanController::class, 'endShift']);

        // Schedules (Calendar)
        Route::prefix('schedules')->group(function () {
            Route::get('/', [\App\Http\Controllers\ScheduleController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\ScheduleController::class, 'store']);
            Route::delete('{id}', [\App\Http\Controllers\ScheduleController::class, 'destroy']);
        });

        // Tambahkan di dalam blok middleware auth:sanctum
        Route::post('/shifts/auto-generate', [App\Http\Controllers\ShiftController::class, 'autoGenerate']);
        Route::get('/shifts/my-schedule', [App\Http\Controllers\ShiftController::class, 'mySchedule']);

        // Khusus untuk Dashboard Manager Vue (CRUD)
        Route::apiResource('shift-karyawans', ShiftKaryawanController::class)->only(['index', 'destroy', 'show']);

        Route::get('/shifts', [ShiftController::class, 'index']);
        Route::post('/shifts', [ShiftController::class, 'store']);
        Route::put('/shifts/{id}', [ShiftController::class, 'update']);
        Route::delete('/shifts/{id}', [ShiftController::class, 'destroy']);

        // ================= HISTORY TRANSACTION =================
        Route::apiResource('history-transactions', HistoryTransactionController::class)->only(['index', 'show', 'update', 'destroy']);

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        // ================= ORDER =================
        Route::prefix('orders')->group(function () {

            Route::get('/', [OrderController::class, 'index']);
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/{order}', [OrderController::class, 'show']);

            // checkout order (create order + items)
            Route::post('/checkout', [OrderController::class, 'checkoutOrder']);

            // cart
            Route::post('/{id}/items', [OrderController::class, 'addItem']);
            Route::delete('/{id}/items/{itemId}', [OrderController::class, 'removeItem']);

            // payment
            Route::post('/{order}/checkout', [OrderController::class, 'checkout']);
            Route::post('/{order}/payments', [OrderController::class, 'pay']);
            Route::patch('/{order}/adjustments', [OrderController::class, 'updateAdjustments']);

            Route::post('/{order}/void-items', [OrderController::class, 'voidItems']);
            Route::put('/{order}/items', [OrderController::class, 'updateItems']);

        });

        // ================= ORDER ITEM (KITCHEN ACTION) =================
        Route::prefix('order-items')->group(function () {

            // update status (pending → cooking → done)
            Route::patch('/{id}/status', [OrderController::class, 'updateItemStatus']);

        });

    });
});
