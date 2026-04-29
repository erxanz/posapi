<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ShiftKaryawan;
use App\Models\Schedule;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftKaryawanController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = ShiftKaryawan::with(['user', 'outlet', 'shift']);

        if ($user->role === 'manager') {
            $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds);
        }

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

        return response()->json(['data' => $shift], 200);
    }

    // =========================================================================
    // FUNGSI VERIFIKASI MANAGER (DENGAN FIX PERHITUNGAN SALDO SISTEM)
    // =========================================================================
    public function resolveAutoClose(Request $request, $id)
    {
        $user = auth()->user();

        if (!in_array($user->role, ['manager', 'developer'])) {
            return response()->json(['message' => 'Hanya Manager yang bisa memverifikasi'], 403);
        }

        $validated = $request->validate([
            'actual_closing_balance' => 'required|integer|min:0',
        ]);

        $shift = ShiftKaryawan::findOrFail($id);

        // 1. HITUNG ULANG uang tunai masuk untuk memastikan data akurat!
        $cashSales = Payment::where('method', 'cash')
            ->whereHas('order', function($query) use ($shift) {
                $query->where('user_id', $shift->user_id)
                      ->where('status', 'paid')
                      ->where('created_at', '>=', $shift->started_at);
            })
            ->sum(DB::raw('amount_paid - change_amount'));

        // 2. Tetapkan saldo yang seharusnya ada (Modal Awal + Penjualan)
        $systemBalance = $shift->opening_balance + $cashSales;

        // 3. Hitung selisih yang BENAR (Aktual dari Manager - Sistem)
        $difference = $validated['actual_closing_balance'] - $systemBalance;

        // 4. Update Database
        $shift->update([
            'status' => 'closed',
            'ended_at' => $shift->ended_at ?? now(),
            'closing_balance_system' => $systemBalance, // Pastikan ini tidak 0/Null lagi
            'closing_balance_actual' => $validated['actual_closing_balance'],
            'difference' => $difference,
            'notes' => $shift->notes . ' | Diverifikasi manual oleh: ' . $user->name,
        ]);

        return response()->json([
            'message' => 'Laporan shift berhasil diverifikasi dan ditutup.',
            'data' => $shift
        ]);
    }

    public function startShift(Request $request)
    {
        $user = auth()->user();
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'opening_balance' => 'required|integer|min:0',
        ]);

        $activeSession = ShiftKaryawan::where('user_id', $user->id)->where('status', 'active')->first();
        if ($activeSession) {
            return response()->json(['message' => 'Anda memiliki shift aktif yang belum ditutup'], 400);
        }

        $today = now()->toDateString();
        $currentAssignedShift = Schedule::where('shift_schedules.user_id', $user->id)
            ->where('shift_schedules.date', $today)
            ->join('shifts', 'shift_schedules.shift_id', '=', 'shifts.id')
            ->select('shifts.*')
            ->first();

        $shiftKaryawan = ShiftKaryawan::create([
            'user_id' => $user->id,
            'outlet_id' => $validated['outlet_id'],
            'shift_id' => $currentAssignedShift->id ?? null,
            'opening_balance' => $validated['opening_balance'],
            'started_at' => now(),
            'status' => 'active',
        ]);

        return response()->json(['message' => 'Shift dimulai', 'data' => $shiftKaryawan], 201);
    }

    public function endShift(Request $request)
    {
        $user = auth()->user();
        $validated = $request->validate([
            'actual_closing_balance' => 'required|integer|min:0',
            'notes' => 'nullable|string'
        ]);

        $shift = ShiftKaryawan::where('user_id', $user->id)->where('status', 'active')->first();
        if (!$shift) return response()->json(['message' => 'Tidak ada shift aktif'], 404);

        $cashSales = Payment::where('method', 'cash')
            ->whereHas('order', function($query) use ($shift) {
                $query->where('user_id', $shift->user_id)
                      ->where('status', 'paid')
                      ->where('created_at', '>=', $shift->started_at);
            })
            ->sum(DB::raw('amount_paid - change_amount'));

        $systemBalance = $shift->opening_balance + $cashSales;

        $shift->update([
            'ended_at' => now(),
            'status' => 'closed',
            'closing_balance_system' => $systemBalance,
            'closing_balance_actual' => $validated['actual_closing_balance'],
            'difference' => $validated['actual_closing_balance'] - $systemBalance,
            'notes' => $validated['notes'],
        ]);

        return response()->json(['message' => 'Shift diakhiri', 'data' => $shift]);
    }

    public function checkStatus(Request $request)
    {
        $user = auth()->user();
        $today = now()->toDateString();

        $activeShift = ShiftKaryawan::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$activeShift) return response()->json(['success' => false]);

        $shiftDate = Carbon::parse($activeShift->started_at)->toDateString();

        if ($shiftDate !== $today) {
            // FIX JUGA DI SINI: Jika auto-close karena aplikasi baru dibuka, hitung saldonya!
            $cashSales = Payment::where('method', 'cash')
                ->whereHas('order', function($query) use ($activeShift) {
                    $query->where('user_id', $activeShift->user_id)
                          ->where('status', 'paid')
                          ->where('created_at', '>=', $activeShift->started_at);
                })
                ->sum(DB::raw('amount_paid - change_amount'));

            $systemBalance = $activeShift->opening_balance + $cashSales;

            $activeShift->update([
                'ended_at' => now(),
                'status' => 'closed',
                'closing_balance_system' => $systemBalance,
                'notes' => 'Auto-closed (Lupa tutup shift tgl ' . $shiftDate . ')',
            ]);
            return response()->json(['success' => false, 'message' => 'Shift kemarin telah ditutup otomatis.']);
        }

        return response()->json(['success' => true, 'data' => $activeShift]);
    }

    public function destroy($id)
    {
        ShiftKaryawan::findOrFail($id)->delete();
        return response()->json(['message' => 'Data dihapus']);
    }
}
