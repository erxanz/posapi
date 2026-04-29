<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Discount;
use App\Models\ShiftKaryawan;
use App\Models\Payment;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


/*
|--------------------------------------------------------------------------
| DAILY ROUTINE COMMAND
|--------------------------------------------------------------------------
| Akan dijalankan otomatis oleh scheduler setiap jam 00:05 WIB
| Fungsi:
| 1. Reset jadwal shift hari ini
| 2. Hapus promo expired
| 3. Auto close shift aktif dari hari sebelumnya
*/

Artisan::command('app:daily-routine', function () {

    $now   = now();
    $today = $now->toDateString();

    Log::info('Daily routine started', [
        'time' => $now->toDateTimeString(),
    ]);

    DB::beginTransaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | 1. Reset Jadwal Shift Hari Ini
        |--------------------------------------------------------------------------
        */
        DB::table('shift_schedules')
            ->whereDate('date', $today)
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | 2. Hapus Promo Expired
        |--------------------------------------------------------------------------
        */
        Discount::whereDate('end_date', '<', $today)->delete();

        /*
        |--------------------------------------------------------------------------
        | 3. Auto Close Shift Lama Yang Masih Aktif
        |--------------------------------------------------------------------------
        */
        $activeShifts = ShiftKaryawan::where('status', 'active')
            ->whereDate('started_at', '<', $today)
            ->get();

        $closedCount = 0;

        foreach ($activeShifts as $shift) {

            $cashSales = Payment::where('method', 'cash')
                ->whereHas('order', function ($query) use ($shift, $now) {
                    $query->where('user_id', $shift->user_id)
                        ->where('outlet_id', $shift->outlet_id)
                        ->where('status', 'paid')
                        ->whereBetween('created_at', [
                            $shift->started_at,
                            $now
                        ]);
                })
                ->sum(DB::raw(
                    'COALESCE(amount_paid,0) - COALESCE(change_amount,0)'
                ));

            $systemBalance = $shift->opening_balance + $cashSales;

            $shift->update([
                'ended_at'               => $now,
                'status'                 => 'closed',
                'closing_balance_system' => $systemBalance,
                'closing_balance_actual' => null,
                'difference'             => null,
                'notes'                  => 'Auto-closed by system at midnight.',
            ]);

            Log::info('Shift auto closed', [
                'shift_id'  => $shift->id,
                'user_id'   => $shift->user_id,
                'outlet_id' => $shift->outlet_id,
            ]);

            $closedCount++;
        }

        DB::commit();

        Log::info('Daily routine success', [
            'closed_shift_total' => $closedCount,
        ]);

    } catch (\Throwable $e) {

        DB::rollBack();

        Log::error('Daily routine failed', [
            'message' => $e->getMessage(),
        ]);
    }

})->purpose('Daily maintenance routine');


/*
|--------------------------------------------------------------------------
| SCHEDULER
|--------------------------------------------------------------------------
| Tidak perlu dijalankan manual:
| php artisan app:daily-routine
|
| Cukup jalankan:
| php artisan schedule:work
|--------------------------------------------------------------------------
*/

Schedule::command('app:daily-routine')
    ->dailyAt('00:05')
    ->timezone('Asia/Jakarta')
    ->name('daily-maintenance')
    ->withoutOverlapping()
    ->runInBackground();
