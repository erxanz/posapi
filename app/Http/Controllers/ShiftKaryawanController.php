<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ShiftKaryawan;
use Illuminate\Http\Request;

class ShiftKaryawanController extends Controller
{
    // ==========================================
    // 1. CRUD UNTUK DASHBOARD MANAGER / ADMIN
    // ==========================================
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = ShiftKaryawan::with(['user', 'outlet']);

        // Filter berdasarkan Role (Manager hanya melihat outlet miliknya)
        if ($user->role === 'manager') {
            $outletIds = \App\Models\Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds);
        }

        // Filter dari dropdown Vue (opsional)
        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        return response()->json($query->latest()->paginate($request->limit ?? 15));
    }

    public function destroy($id)
    {
        $shift = ShiftKaryawan::findOrFail($id);
        $shift->delete();
        return response()->json(['message' => 'Data shift berhasil dihapus.']);
    }

    // ==========================================
    // 2. FUNGSI UNTUK APLIKASI KASIR (FLUTTER)
    // ==========================================
    public function startShift(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'opening_balance' => 'required|integer|min:0',
        ]);

        $activeShift = ShiftKaryawan::where('user_id', auth()->id())
            ->where('status', 'active')
            ->first();

        if ($activeShift) {
            return response()->json(['message' => 'Anda masih memiliki shift aktif'], 400);
        }

        $today = now()->toDateString();
        $lastShift = ShiftKaryawan::where('user_id', auth()->id())
            ->where('outlet_id', $validated['outlet_id'])
            ->whereDate('started_at', $today)
            ->max('shift_ke') ?? 0;

        $shiftKe = $lastShift + 1;

        $shift = ShiftKaryawan::create([
            'user_id' => auth()->id(),
            'outlet_id' => $validated['outlet_id'],
            'shift_ke' => $shiftKe,
            'opening_balance' => $validated['opening_balance'],
            'started_at' => now(),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Shift berhasil dimulai',
            'data' => $shift
        ], 201);
    }

    public function endShift(Request $request)
    {
        $validated = $request->validate([
            'actual_closing_balance' => 'required|integer|min:0',
            'notes' => 'nullable|string'
        ]);

        $shift = ShiftKaryawan::where('user_id', auth()->id())
            ->where('status', 'active')
            ->first();

        if (!$shift) {
            return response()->json(['message' => 'Tidak ada shift aktif'], 404);
        }

        // Ambil penjualan CASH saja untuk drawer
        $cashSales = Payment::where('method', 'cash')
            ->whereHas('order', function($query) use ($shift) {
                $query->where('user_id', $shift->user_id)
                      ->where('outlet_id', $shift->outlet_id)
                      ->where('status', 'paid')
                      ->where('created_at', '>=', $shift->started_at);
            })
            ->where('paid_by', $shift->user_id)
            ->get()
            ->sum(function($payment) {
                return $payment->amount_paid - $payment->change_amount;
            });

        // Kalkulasi ekspektasi sistem dan selisih
        $systemBalance = $shift->opening_balance + $cashSales;
        $difference = $validated['actual_closing_balance'] - $systemBalance;

        // Update data shift
        $shift->update([
            'ended_at' => now(),
            'status' => 'closed',
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
