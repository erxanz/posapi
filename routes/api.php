<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;

use App\Http\Controllers\OrderAcceptanceController;

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
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StockController;

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | PUBLIC ROUTES
    |--------------------------------------------------------------------------
    */
    // ================= PUBLIC QR =================
    Route::prefix('public')->group(function () {
        Route::get('/menu/{token}', [ProductController::class, 'publicMenu'])->name('public.menu');
        Route::post('/order', [OrderController::class, 'publicOrder'])->name('public.order');

        // TAMBAHAN BARU: Ambil pajak dan diskon untuk halaman QR
        // PENTING: outlet_id WAJIB dan dipakai untuk filter, supaya endpoint
        // publik ini tidak membocorkan data pajak/diskon milik SEMUA tenant
        // ke siapa pun yang scan QR outlet manapun (sebelumnya tanpa filter
        // sama sekali). Lihat juga validasi ulang di OrderService saat order
        // dibuat - discount_id/tax_id yang dikirim tetap harus divalidasi
        // ulang di sana sebagai defense kedua.
        Route::get('/taxes', function (\Illuminate\Http\Request $request) {
            $outletId = $request->integer('outlet_id');
            if (!$outletId) {
                return response()->json(['message' => 'outlet_id wajib diisi'], 422);
            }
            return response()->json(\App\Models\Tax::where('active', true)->where('outlet_id', $outletId)->get());
        });

        Route::get('/discounts', function (\Illuminate\Http\Request $request) {
            $outletId = $request->integer('outlet_id');
            if (!$outletId) {
                return response()->json(['message' => 'outlet_id wajib diisi'], 422);
            }
            $ownerId = \App\Models\Outlet::query()->whereKey($outletId)->value('owner_id');
            if (!$ownerId) {
                return response()->json([]);
            }
            return response()->json(\App\Models\Discount::where('is_active', true)->where('owner_id', $ownerId)->get());
        });

        Route::get('/order/{id}', [OrderController::class, 'publicShow'])->name('public.order.show');
        // Proxy unduh gambar QRIS Midtrans. Midtrans tidak mengirim header CORS di
        // endpoint QR-nya, jadi browser tidak bisa fetch/canvas langsung. Endpoint
        // ini mengambil gambarnya server-side lalu mengirim balik sebagai attachment.
        Route::get('/qr-image', [OrderController::class, 'downloadQrImage'])->name('public.qr.image');
        Route::get('/top-products', [App\Http\Controllers\ReportController::class, 'publicTopProducts']);
    });

    // ================= AUTH =================
    Route::post('/register', [AuthController::class, 'register'])->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::post('/login-pin', [AuthController::class, 'loginPin'])
        ->middleware('throttle:5,1')
        ->name('login.pin');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->name('password.forgot');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->name('password.reset');

    // ================= MIDTRANS CALLBACK =================
    Route::post('/midtrans/callback', [OrderController::class, 'midtransCallback'])
        ->name('midtrans.callback');

    /*
    |--------------------------------------------------------------------------
    | PROTECTED ROUTES
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function () {

        // Broadcasting routes untuk Reverb
        Broadcast::routes(['middleware' => ['auth:sanctum']]);

        /*
        |--------------------------------------------------------------------------
        | AUTH USER
        |--------------------------------------------------------------------------
        */

        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::put('/me', [AuthController::class, 'updateProfile'])->name('me.update');

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
        | STOCKS
        |--------------------------------------------------------------------------
        */

        Route::post('/stocks/adjust', [StockController::class, 'adjust'])
            ->name('stocks.adjust');

        Route::apiResource('stocks', StockController::class);

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

            Route::get('/check-status', [ShiftKaryawanController::class, 'checkStatus']);

            // Flutter Cashier
            Route::post('/start', [ShiftKaryawanController::class, 'startShift']);
            Route::post('/end', [ShiftKaryawanController::class, 'endShift']);

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

            // Cashier accept / kitchen accept (trigger print on mobile)
            Route::post('/{order}/accept', [OrderAcceptanceController::class, 'accept'])
                ->name('orders.accept');
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/{order}', [OrderController::class, 'show']);

            // Checkout
            Route::post('/checkout', [OrderController::class, 'checkoutOrder']);

            // Cart
            Route::post('/{id}/items', [OrderController::class, 'addItem']);
            Route::delete('/{id}/items/{itemId}', [OrderController::class, 'removeItem']);

            // Cancel single item (partial or full)
            Route::post('/{order}/items/{item}/cancel', [OrderController::class, 'cancelItem']);

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
