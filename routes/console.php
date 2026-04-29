<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use App\Models\Discount;
use App\Models\ShiftKaryawan;
use App\Models\Payment;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ==========================================
// SCHEDULER: RUTINITAS HARIAN (Berjalan Setiap 00:00)
// ==========================================
Schedule::call(function () {
    // 1. Reset Jadwal Shift Schedules (Hati-hati, ini menghapus jadwal hari ini. Pastikan ini sesuai kebutuhan bisnis)
    DB::table('shift_schedules')->whereDate('date', now()->toDateString())->delete();

    // 2. Auto-Delete Promo yang sudah lewat tanggal End Date-nya
    Discount::whereDate('end_date', '<', now()->format('Y-m-d'))->delete();

    // 3. AUTO-CLOSE SHIFT AKTIF DARI HARI SEBELUMNYA
    $activeShifts = ShiftKaryawan::where('status', 'active')
        ->whereDate('started_at', '<', now()->toDateString())
        ->get();

    foreach ($activeShifts as $shift) {
        // Hitung total penjualan cash selama shift berlangsung
        $cashSales = Payment::where('method', 'cash')
            ->whereHas('order', function($query) use ($shift) {
                $query->where('user_id', $shift->user_id)
                      ->where('outlet_id', $shift->outlet_id)
                      ->where('status', 'paid')
                      ->where('created_at', '>=', $shift->started_at);
            })
            ->get()
            ->sum(function($payment) {
                return $payment->amount_paid - $payment->change_amount;
            });

        $systemBalance = $shift->opening_balance + $cashSales;

        // Tutup shift, set uang aktual menjadi null agar butuh verifikasi Manager
        $shift->update([
            'ended_at' => now(),
            'status' => 'closed',
            'closing_balance_system' => $systemBalance,
            'closing_balance_actual' => null,
            'difference' => null,
            'notes' => 'Auto-closed by System (Cron Job) at midnight. Lupa tutup shift.',
        ]);
    }

})
->dailyAt('00:05')
->timezone('Asia/Jakarta')
->name('daily-maintenance')
->withoutOverlapping()
->runInBackground();
