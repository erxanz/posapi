<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any}', function () {
    return view('app'); // atau nama blade Vue kamu
})->where('any', '.*');

Route::get('/', function () {
    return view('welcome');
});
