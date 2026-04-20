<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use App\Models\Discount;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ==========================================
// SCHEDULER: RUTINITAS HARIAN
// ==========================================
Schedule::call(function () {
    // 1. Reset Jadwal Shift (Dari fitur sebelumnya)
    DB::table('shift_user')->truncate();

    // 2. Auto-Delete Promo yang sudah lewat tanggal End Date-nya
    Discount::whereDate('end_date', '<', now()->format('Y-m-d'))->delete();

})->dailyAt('00:00')->timezone('Asia/Jakarta');
