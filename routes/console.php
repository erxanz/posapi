<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Discount;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


/*
|--------------------------------------------------------------------------
| DAILY SYSTEM MAINTENANCE
|--------------------------------------------------------------------------
*/

Schedule::call(function () {

    DB::beginTransaction();

    try {

        // =====================================================
        // 1. HAPUS JADWAL SHIFT YANG SUDAH LEWAT (kemarin ke bawah)
        // =====================================================
        DB::table('shift_schedules')
            ->whereDate('date', '<', now()->toDateString())
            ->delete();

        // =====================================================
        // 2. NONAKTIFKAN PROMO EXPIRED (lebih aman daripada delete)
        // =====================================================
        Discount::whereDate('end_date', '<', today())
            ->update([
                'is_active' => false,
                'updated_at' => now()
            ]);

        DB::commit();

        Log::info('Daily scheduler success');

    } catch (\Throwable $e) {

        DB::rollBack();

        Log::error('Daily scheduler failed: ' . $e->getMessage());
    }

})
->dailyAt('00:05')
->timezone('Asia/Jakarta')
->name('daily-maintenance')
->withoutOverlapping()
->runInBackground();
