<?php

use Illuminate\Support\Facades\Route;

// Controllers
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\HistoryTransactionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftKaryawanController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\UserController;

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | PUBLIC ROUTES
    |--------------------------------------------------------------------------
    */

    // ================= AUTH =================
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('login');

        Route::post('/login-pin', [AuthController::class, 'loginPin'])
            ->middleware('throttle:5,1')
            ->name('login.pin');

        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
    });

    // ================= MIDTRANS CALLBACK =================
    Route::post('/midtrans/callback', [OrderController::class, 'midtransCallback'])
        ->name('midtrans.callback');

    // ================= PUBLIC QR =================
    Route::prefix('public')->group(function () {
        Route::get('/menu/{token}', [ProductController::class, 'publicMenu'])->name('public.menu');
        Route::post('/order', [OrderController::class, 'publicOrder'])->name('public.order');
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
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout.all');
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::put('/me', [AuthController::class, 'updateProfile'])->name('me.update');
        });

        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
            ->name('dashboard.summary');

        /*
        |--------------------------------------------------------------------------
        | USERS
        |--------------------------------------------------------------------------
        */

        Route::prefix('users')->group(function () {
            Route::post('/', [UserController::class, 'createUser'])->name('users.create');
            Route::get('/', [UserController::class, 'listUsers'])->name('users.list');
            Route::get('/{id}', [UserController::class, 'showUser'])->name('users.show');
            Route::put('/{id}', [UserController::class, 'updateUser'])->name('users.update');
            Route::delete('/{id}', [UserController::class, 'deleteUser'])->name('users.delete');
        });

        /*
        |--------------------------------------------------------------------------
        | OUTLETS
        |--------------------------------------------------------------------------
        */

        Route::prefix('outlets')->group(function () {
            Route::post('/', [OutletController::class, 'createOutlet'])->name('outlets.create');
            Route::get('/', [OutletController::class, 'index'])->name('outlets.index');
            Route::get('/{outlet}', [OutletController::class, 'show'])->name('outlets.show');
            Route::put('/{outlet}', [OutletController::class, 'update'])->name('outlets.update');
            Route::delete('/{outlet}', [OutletController::class, 'destroy'])->name('outlets.destroy');

            Route::get('/{outlet}/products', [OutletController::class, 'getProducts'])
                ->name('outlets.products');

            Route::post('/{outlet}/sync-products', [OutletController::class, 'syncProducts'])
                ->name('outlets.sync-products');
        });

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

        Route::get('/stations/{id}/products', [StationController::class, 'products'])
            ->name('stations.products');

        /*
        |--------------------------------------------------------------------------
        | STOCKS
        |--------------------------------------------------------------------------
        */

        Route::post('/stocks/adjust', [StockController::class, 'adjust'])
            ->name('stocks.adjust');

        Route::apiResource('stocks', StockController::class);

        /*
        |--------------------------------------------------------------------------
        | SHIFTS MASTER
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
        | SHIFT KARYAWAN
        |--------------------------------------------------------------------------
        */

        Route::prefix('shift-karyawans')->group(function () {

            // Flutter Cashier
            Route::post('/start', [ShiftKaryawanController::class, 'startShift']);
            Route::post('/end', [ShiftKaryawanController::class, 'endShift']);
            Route::get('/check-status', [ShiftKaryawanController::class, 'checkStatus']);
            Route::get('/active', [ShiftKaryawanController::class, 'active']);
            Route::get('/history', [ShiftKaryawanController::class, 'history']);

            // Manager
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

            // Void
            Route::post('/{order}/void-items', [OrderController::class, 'voidItems']);

            // Update Items
            Route::put('/{order}/items', [OrderController::class, 'updateItems']);
        });

        /*
        |--------------------------------------------------------------------------
        | ORDER ITEMS (KITCHEN)
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
            Route::get('/', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/export', [ReportController::class, 'export'])->name('reports.export');
        });
    });
});
