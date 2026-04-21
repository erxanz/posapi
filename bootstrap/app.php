<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // middleware ini untuk menangkap token dari URL
        $middleware->prepend(function ($request, $next) {
            // Cek apakah ada parameter 'token' di URL DAN Header Authorization asli kosong
            // Tambahkan pengecekan agar hanya berjalan jika 'token' tidak kosong
            if ($request->filled('token') && !$request->bearerToken()) {
                $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));
            }

            return $next($request);
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
