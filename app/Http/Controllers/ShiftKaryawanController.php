<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ShiftKaryawan;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Carbon\Carbon;

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

    // Fungsi untuk Manager verifikasi uang fisik dari Dashboard Vue (setelah auto-close)
    public function resolveAutoClose(Request $request, $id)
    {
        $user = auth()->user();

        if (!in_array($user->role, ['manager', 'developer'])) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $validated = $request->validate([
            'actual_closing_balance' => 'required|integer|min:0',
        ]);

        $shift = ShiftKaryawan::findOrFail($id);

        if ($shift->closing_balance_actual !== null) {
            return response()->json(['message' => 'Laporan shift ini sudah memiliki data uang aktual'], 400);
        }

        $difference = $validated['actual_closing_balance'] - $shift->closing_balance_system;

        $shift->update([
            'closing_balance_actual' => $validated['actual_closing_balance'],
            'difference' => $difference,
            'notes' => $shift->notes . ' | Telah diverifikasi manual oleh Manajer: ' . $user->name,
        ]);

        return response()->json([
            'message' => 'Laporan shift berhasil diverifikasi',
            'data' => $shift
        ]);
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
            'opening_balance' => 'required|integer|min:0',
        ]);

        if ((int) $user->outlet_id !== (int) $validated['outlet_id']) {
            return response()->json(['message' => 'Outlet tidak sesuai dengan akun karyawan'], 403);
        }

        $currentTime = now()->format('H:i:s');
        $today = now()->toDateString();

        $currentAssignedShift = Schedule::where('shift_schedules.user_id', $user->id)
            ->where('shift_schedules.outlet_id', $validated['outlet_id'])
            ->where('shift_schedules.date', $today)
            ->join('shifts', 'shift_schedules.shift_id', '=', 'shifts.id')
            ->where(function ($query) use ($currentTime) {
                $query
                    ->where(function ($q) use ($currentTime) {
                        $q->whereColumn('shifts.start_time', '<=', 'shifts.end_time')
                            ->whereTime('shifts.start_time', '<=', $currentTime)
                            ->whereTime('shifts.end_time', '>=', $currentTime);
                    })
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

        $activeSession = ShiftKaryawan::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($activeSession) {
            return response()->json(['message' => 'Anda masih memiliki shift aktif yang belum ditutup'], 400);
        }

        $shiftKaryawan = ShiftKaryawan::create([
            'user_id' => $user->id,
            'outlet_id' => $validated['outlet_id'],
            'shift_id' => $currentAssignedShift->id,
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

        $systemBalance = $shift->opening_balance + $cashSales;
        $difference = $validated['actual_closing_balance'] - $systemBalance;

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

    // ==========================================
    // 3. TAMBAHAN: CEK STATUS & AUTO-CLOSE
    // ==========================================
    public function checkStatus(Request $request)
    {
        $user = auth()->user();
        $outletId = $request->query('outlet_id');
        $today = now()->toDateString();

        // Cari shift yang masih 'active'
        $activeShift = ShiftKaryawan::where('user_id', $user->id)
            ->where('outlet_id', $outletId)
            ->where('status', 'active')
            ->first();

        if (!$activeShift) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada shift aktif hari ini.'
            ]);
        }

        // Cek apakah shift tersebut berasal dari hari yang lalu
        $shiftDate = Carbon::parse($activeShift->started_at)->toDateString();

        if ($shiftDate !== $today) {
            // --- PROSES AUTO-CLOSE ---
            // Hitung penjualan cash terakhir agar laporan tetap sinkron
            $cashSales = Payment::where('method', 'cash')
                ->whereHas('order', function($query) use ($activeShift) {
                    $query->where('user_id', $activeShift->user_id)
                          ->where('outlet_id', $activeShift->outlet_id)
                          ->where('status', 'paid')
                          ->where('created_at', '>=', $activeShift->started_at);
                })
                ->get()
                ->sum(function($payment) {
                    return $payment->amount_paid - $payment->change_amount;
                });

            $systemBalance = $activeShift->opening_balance + $cashSales;

            // Tutup shift secara otomatis
            $activeShift->update([
                'ended_at' => now(),
                'status' => 'closed',
                'closing_balance_system' => $systemBalance,
                'closing_balance_actual' => $systemBalance,
                'difference' => 0,
                'notes' => 'Auto-closed by System (Forgot to close on ' . $shiftDate . ')',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Shift kemarin telah ditutup otomatis. Silakan input kas awal baru.'
            ]);
        }

        // Jika shift hari ini, kembalikan data untuk bypass kas awal
        return response()->json([
            'success' => true,
            'message' => 'Shift aktif ditemukan.',
            'data' => $activeShift->load(['shift', 'outlet'])
        ]);
    }
}
