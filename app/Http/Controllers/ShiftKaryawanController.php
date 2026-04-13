<?php

namespace App\Http\Controllers;

use App\Models\ShiftKaryawan;
use App\Models\Payment;
use Illuminate\Http\Request;

class ShiftKaryawanController extends Controller
{
    public function startShift(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'opening_balance' => 'required|integer|min:0', // Validasi modal awal
        ]);

        $activeShift = ShiftKaryawan::where('user_id', auth()->id())
            ->where('status', 'aktif')
            ->first();

        if ($activeShift) {
            return response()->json(['message' => 'Anda masih memiliki shift aktif'], 400);
        }

        $shift = ShiftKaryawan::create([
            'user_id' => auth()->id(),
            'outlet_id' => $validated['outlet_id'],
            'waktu_mulai' => now(),
            'opening_balance' => $validated['opening_balance'],
            'status' => 'aktif',
        ]);

        return response()->json([
            'message' => 'Shift berhasil dimulai',
            'data' => $shift
        ], 201);
    }

    public function endShift(Request $request)
    {
        $validated = $request->validate([
            'actual_closing_balance' => 'required|integer|min:0', // Uang fisik aktual yang dihitung kasir
            'notes' => 'nullable|string'
        ]);

        $shift = ShiftKaryawan::where('user_id', auth()->id())
            ->where('status', 'aktif')
            ->first();

        if (!$shift) {
            return response()->json(['message' => 'Tidak ada shift aktif'], 404);
        }

        // Hitung total uang tunai (Cash) yang masuk selama shift ini dari tabel Payment
        $cashSales = Payment::where('payment_method', 'cash')
            ->where('status', 'paid')
            ->whereHas('order', function($query) use ($shift) {
                $query->where('user_id', $shift->user_id)
                      ->where('outlet_id', $shift->outlet_id)
                      ->where('created_at', '>=', $shift->waktu_mulai);
            })
            ->sum('amount');

        // Kalkulasi ekspektasi sistem dan selisih
        $systemBalance = $shift->opening_balance + $cashSales;
        $difference = $validated['actual_closing_balance'] - $systemBalance;

        // Update data shift
        $shift->update([
            'waktu_selesai' => now(),
            'status' => 'selesai',
            'closing_balance_system' => $systemBalance,
            'closing_balance_actual' => $validated['actual_closing_balance'],
            'difference' => $difference,
            'notes' => $validated['notes'],
        ]);

        return response()->json([
            'message' => 'Shift berhasil diakhiri',
            'summary' => [
                'opening_balance' => $shift->opening_balance,
                'total_cash_sales' => $cashSales,
                'expected_in_drawer' => $systemBalance,
                'actual_in_drawer' => $shift->closing_balance_actual,
                'difference' => $difference,
                'status_laci' => $difference === 0 ? 'Balance' : ($difference > 0 ? 'Uang Lebih' : 'Uang Kurang')
            ],
            'data' => $shift
        ]);
    }
}
