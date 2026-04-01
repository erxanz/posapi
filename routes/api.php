<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // AUTH
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me', [AuthController::class, 'me']);

        // CATEGORY
        Route::apiResource('categories', CategoryController::class);

        // PRODUCT (MENU)
        Route::apiResource('products', ProductController::class);

        // ORDER (POS)
        Route::apiResource('orders', OrderController::class);

    });
});
