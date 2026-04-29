<?php

use Illuminate\Support\Facades\Route;

// Controllers
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftKaryawanController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\HistoryTransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\DashboardController;

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | PUBLIC ROUTES
    |--------------------------------------------------------------------------
    */

    // AUTH
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/login-pin', [AuthController::class, 'loginPin'])->middleware('throttle:5,1');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    // MIDTRANS CALLBACK
    Route::post('/midtrans/callback', [OrderController::class, 'midtransCallback']);

    // PUBLIC QR MENU
    Route::prefix('public')->group(function () {
        Route::get('/menu/{token}', [ProductController::class, 'publicMenu']);
        Route::post('/order', [OrderController::class, 'publicOrder']);
    });

    /*
    |--------------------------------------------------------------------------
    | PROTECTED ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | AUTH USER
        |--------------------------------------------------------------------------
        */

        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/me', [AuthController::class, 'updateProfile']);
        });

        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

        /*
        |--------------------------------------------------------------------------
        | USERS
        |--------------------------------------------------------------------------
        */

        Route::apiResource('users', UserController::class);

        /*
        |--------------------------------------------------------------------------
        | OUTLETS
        |--------------------------------------------------------------------------
        */

        Route::apiResource('outlets', OutletController::class);

        Route::get('/outlets/{outlet}/products', [OutletController::class, 'getProducts']);
        Route::post('/outlets/{outlet}/sync-products', [OutletController::class, 'syncProducts']);

        /*
        |--------------------------------------------------------------------------
        | MASTER DATA
        |--------------------------------------------------------------------------
        */

        Route::apiResource('tables', TableController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('products', ProductController::class);
        Route::apiResource('discounts', DiscountController::class);
        Route::apiResource('taxes', TaxController::class);

        /*
        |--------------------------------------------------------------------------
        | STATIONS
        |--------------------------------------------------------------------------
        */

        Route::apiResource('stations', StationController::class);
        Route::get('/stations/{id}/products', [StationController::class, 'products']);

        /*
        |--------------------------------------------------------------------------
        | STOCKS
        |--------------------------------------------------------------------------
        */

        Route::post('/stocks/adjust', [StockController::class, 'adjust']);
        Route::apiResource('stocks', StockController::class);

        /*
        |--------------------------------------------------------------------------
        | MASTER SHIFT
        |--------------------------------------------------------------------------
        */

        Route::prefix('shifts')->group(function () {
            Route::get('/', [ShiftController::class, 'index']);
            Route::post('/', [ShiftController::class, 'store']);
            Route::put('/{id}', [ShiftController::class, 'update']);
            Route::delete('/{id}', [ShiftController::class, 'destroy']);

            Route::post('/auto-generate', [ShiftController::class, 'autoGenerate']);
            Route::get('/my-schedule', [ShiftController::class, 'mySchedule']);
        });

        /*
        |--------------------------------------------------------------------------
        | SHIFT KARYAWAN (FLUTTER / KASIR)
        |--------------------------------------------------------------------------
        */

        Route::prefix('shift-karyawans')->group(function () {

            // Flutter Cashier
            Route::post('/start', [ShiftKaryawanController::class, 'startShift']);
            Route::post('/end', [ShiftKaryawanController::class, 'endShift']);
            Route::get('/check-status', [ShiftKaryawanController::class, 'checkStatus']);
            Route::get('/active', [ShiftKaryawanController::class, 'active']);
            Route::get('/history', [ShiftKaryawanController::class, 'history']);

            // Manager Dashboard
            Route::put('/{id}/resolve', [ShiftKaryawanController::class, 'resolveAutoClose']);

            // CRUD
            Route::get('/', [ShiftKaryawanController::class, 'index']);
            Route::get('/{id}', [ShiftKaryawanController::class, 'show']);
            Route::delete('/{id}', [ShiftKaryawanController::class, 'destroy']);
        });

        /*
        |--------------------------------------------------------------------------
        | SCHEDULES
        |--------------------------------------------------------------------------
        */

        Route::prefix('schedules')->group(function () {
            Route::get('/', [ScheduleController::class, 'index']);
            Route::post('/', [ScheduleController::class, 'store']);
            Route::delete('/{id}', [ScheduleController::class, 'destroy']);
        });

        /*
        |--------------------------------------------------------------------------
        | ORDERS
        |--------------------------------------------------------------------------
        */

        Route::prefix('orders')->group(function () {

            Route::get('/', [OrderController::class, 'index']);
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/{order}', [OrderController::class, 'show']);

            // Checkout
            Route::post('/checkout', [OrderController::class, 'checkoutOrder']);

            // Cart
            Route::post('/{id}/items', [OrderController::class, 'addItem']);
            Route::delete('/{id}/items/{itemId}', [OrderController::class, 'removeItem']);

            // Payment
            Route::post('/{order}/checkout', [OrderController::class, 'checkout']);
            Route::post('/{order}/payments', [OrderController::class, 'pay']);

            // Adjustment
            Route::patch('/{order}/adjustments', [OrderController::class, 'updateAdjustments']);

            // Void / Update Item
            Route::post('/{order}/void-items', [OrderController::class, 'voidItems']);
            Route::put('/{order}/items', [OrderController::class, 'updateItems']);
        });

        /*
        |--------------------------------------------------------------------------
        | ORDER ITEMS (KITCHEN DISPLAY)
        |--------------------------------------------------------------------------
        */

        Route::prefix('order-items')->group(function () {
            Route::patch('/{id}/status', [OrderController::class, 'updateItemStatus']);
        });

        /*
        |--------------------------------------------------------------------------
        | HISTORY TRANSACTION
        |--------------------------------------------------------------------------
        */

        Route::apiResource('history-transactions', HistoryTransactionController::class)
            ->only(['index', 'show', 'update', 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | REPORTS
        |--------------------------------------------------------------------------
        */

        Route::prefix('reports')->group(function () {
            Route::get('/', [ReportController::class, 'index']);
            Route::get('/export', [ReportController::class, 'export']);
        });
    });
});
