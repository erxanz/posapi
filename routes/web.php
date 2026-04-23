<?php

use Illuminate\Support\Facades\Route;
use App\Events\TestRealtime;

Route::get('/test-realtime', function () {
    broadcast(new TestRealtime('Halo dari Backend Laravel'));
    return 'Event dikirim!';
});

Route::get('/', function () {
    return view('welcome');
});
