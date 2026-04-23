<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ShiftKaryawan;
use App\Models\Schedule;
use Illuminate\Http\Request;

class ShiftKaryawanController extends Controller
{
    // ==========================================
    // 1. CRUD UNTUK DASHBOARD MANAGER / ADMIN
    // ==========================================
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = ShiftKaryawan::with(['user', 'outlet', 'shift']);

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

    public function show($id)
    {
        $authUser = auth()->user();
        $shift = ShiftKaryawan::with(['user:id,name', 'outlet:id,name', 'shift:id,name,start_time,end_time,outlet_id'])->findOrFail($id);

        if ($authUser->role === 'karyawan' && (int) $shift->user_id !== (int) $authUser->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        if ($authUser->role === 'manager') {
            $ownsOutlet = \App\Models\Outlet::where('id', $shift->outlet_id)->where('owner_id', $authUser->id)->exists();
            if (!$ownsOutlet) {
                return response()->json(['message' => 'Akses ditolak'], 403);
            }
        }

        return response()->json(['data' => $shift], 200);
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
        $user = auth()->user();
        if ($user->role !== 'karyawan') {
            return response()->json(['message' => 'Hanya karyawan yang dapat memulai shift'], 403);
        }

        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            // 'shift_id' dihapus dari sini karena Flutter tidak mengirimkannya lagi
            'opening_balance' => 'required|integer|min:0',
        ]);

        if ((int) $user->outlet_id !== (int) $validated['outlet_id']) {
            return response()->json(['message' => 'Outlet tidak sesuai dengan akun karyawan'], 403);
        }

        $currentTime = now()->format('H:i:s');
        $today = now()->toDateString();

        // 1. CARI JADWAL OTOMATIS: Cek tabel shift_schedules untuk hari ini + cocokkan jam
        $currentAssignedShift = Schedule::where('shift_schedules.user_id', $user->id)
            ->where('shift_schedules.outlet_id', $validated['outlet_id'])
            ->where('shift_schedules.date', $today)
            ->join('shifts', 'shift_schedules.shift_id', '=', 'shifts.id')
            ->where(function ($query) use ($currentTime) {
                $query
                    // Shift normal
                    ->where(function ($q) use ($currentTime) {
                        $q->whereColumn('shifts.start_time', '<=', 'shifts.end_time')
                            ->whereTime('shifts.start_time', '<=', $currentTime)
                            ->whereTime('shifts.end_time', '>=', $currentTime);
                    })
                    // Shift lintas malam
                    ->orWhere(function ($q) use ($currentTime) {
                        $q->whereColumn('shifts.start_time', '>', 'shifts.end_time')
                            ->where(function ($q2) use ($currentTime) {
                                $q2->whereTime('shifts.start_time', '<=', $currentTime)
                                    ->orWhereTime('shifts.end_time', '>=', $currentTime);
                            });
                    });
            })
            ->select('shifts.*')
            ->first();

        if (!$currentAssignedShift) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal shift pada hari dan jam ini.'
            ], 403);
        }

        // 2. Cek apakah karyawan masih punya sesi kasir yang belum ditutup
        $activeSession = ShiftKaryawan::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($activeSession) {
            return response()->json(['message' => 'Anda masih memiliki shift aktif yang belum ditutup'], 400);
        }

        // 3. Buat sesi kasir menggunakan ID shift yang ditemukan secara otomatis
        $shiftKaryawan = ShiftKaryawan::create([
            'user_id' => $user->id,
            'outlet_id' => $validated['outlet_id'],
            'shift_id' => $currentAssignedShift->id, // Diambil otomatis dari sistem
            'opening_balance' => $validated['opening_balance'],
            'started_at' => now(),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Shift berhasil dimulai otomatis sesuai jadwal',
            'data' => $shiftKaryawan->load(['shift:id,name,start_time,end_time,outlet_id', 'outlet:id,name'])
        ], 201);
    }

    public function endShift(Request $request)
    {
        $user = auth()->user();
        if ($user->role !== 'karyawan') {
            return response()->json(['message' => 'Hanya karyawan yang dapat mengakhiri shift'], 403);
        }

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
