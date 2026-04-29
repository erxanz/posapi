<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use App\Models\Discount;
use App\Models\ShiftKaryawan;
use App\Models\Payment;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {

    $now = now();
    $today = $now->toDateString();

    // Reset jadwal hari ini
    DB::table('shift_schedules')
        ->whereDate('date', $today)
        ->delete();

    // Hapus promo expired
    Discount::whereDate('end_date', '<', $today)->delete();

    // Cari shift aktif dari hari sebelumnya
    $activeShifts = ShiftKaryawan::where('status', 'active')
        ->whereDate('started_at', '<', $today)
        ->get();

    foreach ($activeShifts as $shift) {

        $cashSales = Payment::where('method', 'cash')
            ->whereHas('order', function ($query) use ($shift, $now) {
                $query->where('user_id', $shift->user_id)
                    ->where('outlet_id', $shift->outlet_id)
                    ->where('status', 'paid')
                    ->whereBetween('created_at', [$shift->started_at, $now]);
            })
            ->sum(DB::raw('amount_paid - change_amount'));

        $systemBalance = $shift->opening_balance + $cashSales;

        $shift->update([
            'ended_at' => $now,
            'status' => 'closed',
            'closing_balance_system' => $systemBalance,
            'closing_balance_actual' => null,
            'difference' => null,
            'notes' => 'Auto-closed by system at midnight.',
        ]);
    }

})
->dailyAt('00:05')
->timezone('Asia/Jakarta')
->name('daily-maintenance')
->withoutOverlapping()
->runInBackground();
