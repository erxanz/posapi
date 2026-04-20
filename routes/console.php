<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// ==========================================
// FITUR BARU: RESET JADWAL SETIAP HARI
// ==========================================
Schedule::call(function () {
    // Menghapus semua penugasan jadwal di tabel pivot setiap tengah malam
    DB::table('shift_user')->truncate();
})->dailyAt('00:00')->timezone('Asia/Jakarta');
