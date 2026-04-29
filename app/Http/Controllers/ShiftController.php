<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Schedule;
use App\Models\Payment;
use App\Models\ShiftKaryawan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ShiftController extends Controller
{
    // =====================================================
    // 1. MASTER SHIFT (MANAGER / ADMIN)
    // =====================================================
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $query = Shift::with('outlet:id,name');

            if ($user->role === 'manager') {
                $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');
                $query->whereIn('outlet_id', $outletIds);
            } elseif ($user->role === 'karyawan') {
                $query->where('outlet_id', $user->outlet_id);
            }

            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->outlet_id);
            }

            return response()->json([
                'data' => $query->latest()->get()
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal mengambil data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'outlet_id'  => 'required|exists:outlets,id',
            'name'       => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
            'color'      => 'nullable|string|max:7',
        ]);

        $user = auth()->user();

        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $validated['outlet_id'])
                ->where('owner_id', $user->id)
                ->exists();

            if (!$isMine) {
                return response()->json(['message' => 'Akses ditolak'], 403);
            }
        }

        DB::beginTransaction();

        try {
            $shift = Shift::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Shift berhasil dibuat',
                'data' => $shift->load('outlet:id,name')
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal menyimpan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'outlet_id'  => 'required|exists:outlets,id',
            'name'       => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
            'color'      => 'nullable|string|max:7',
        ]);

        $shift = Shift::findOrFail($id);

        DB::beginTransaction();

        try {
            $shift->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Shift berhasil diupdate',
                'data' => $shift->load('outlet:id,name')
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal update',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Shift::findOrFail($id)->delete();

            return response()->json([
                'message' => 'Shift berhasil dihapus'
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal menghapus',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =====================================================
    // 2. AUTO GENERATE JADWAL BULANAN
    // =====================================================
    public function autoGenerate(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'month'     => 'nullable|integer|min:1|max:12',
            'year'      => 'nullable|integer'
        ]);

        $outletId = $validated['outlet_id'];

        $shifts = Shift::where('outlet_id', $outletId)->get();

        if ($shifts->isEmpty()) {
            return response()->json([
                'message' => 'Belum ada master shift'
            ], 400);
        }

        $karyawans = User::where('outlet_id', $outletId)
            ->where('role', 'karyawan')
            ->pluck('id')
            ->toArray();

        if (empty($karyawans)) {
            return response()->json([
                'message' => 'Tidak ada karyawan'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $targetDate = now();

            if ($request->filled('month') && $request->filled('year')) {
                $targetDate = Carbon::create(
                    $validated['year'],
                    $validated['month'],
                    1
                );
            }

            $start = $targetDate->copy()->startOfMonth();
            $end   = $targetDate->copy()->endOfMonth();

            Schedule::where('outlet_id', $outletId)
                ->whereBetween('date', [$start, $end])
                ->delete();

            $newSchedules = [];
            $empPerShift = ceil(count($karyawans) / $shifts->count());

            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {

                $daily = $karyawans;
                shuffle($daily);

                $chunks = array_chunk($daily, $empPerShift);

                foreach ($shifts as $i => $shift) {
                    foreach ($chunks[$i] ?? [] as $userId) {
                        $newSchedules[] = [
                            'outlet_id'  => $outletId,
                            'shift_id'   => $shift->id,
                            'user_id'    => $userId,
                            'date'       => $date->toDateString(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }

            foreach (array_chunk($newSchedules, 100) as $chunk) {
                Schedule::insert($chunk);
            }

            DB::commit();

            return response()->json([
                'message' => 'Jadwal berhasil digenerate'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal generate',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =====================================================
    // 3. JADWAL SAYA (KARYAWAN)
    // =====================================================
    public function mySchedule()
    {
        $user = auth()->user();

        $data = Schedule::where('user_id', $user->id)
            ->where('date', now()->toDateString())
            ->with('shift.outlet:id,name', 'shift:id,name,start_time,end_time,color')
            ->get()
            ->pluck('shift')
            ->values();

        return response()->json([
            'data' => $data
        ]);
    }

    // =====================================================
    // 4. START SHIFT (KASIR)
    // =====================================================
    public function startShift(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'opening_balance' => 'required|integer|min:0',
        ]);

        $today = now()->toDateString();
        $currentTime = now()->format('H:i:s');

        $assignedShift = Schedule::where('user_id', $user->id)
            ->where('outlet_id', $validated['outlet_id'])
            ->where('date', $today)
            ->join('shifts', 'shift_schedules.shift_id', '=', 'shifts.id')
            ->whereTime('shifts.start_time', '<=', $currentTime)
            ->whereTime('shifts.end_time', '>=', $currentTime)
            ->select('shifts.*')
            ->first();

        if (!$assignedShift) {
            return response()->json([
                'message' => 'Tidak ada jadwal shift saat ini'
            ], 403);
        }

        $active = ShiftKaryawan::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($active) {
            return response()->json([
                'message' => 'Masih ada shift aktif'
            ], 400);
        }

        $shift = ShiftKaryawan::create([
            'user_id' => $user->id,
            'outlet_id' => $validated['outlet_id'],
            'shift_id' => $assignedShift->id,
            'opening_balance' => $validated['opening_balance'],
            'started_at' => now(),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Shift dimulai',
            'data' => $shift
        ]);
    }

    // =====================================================
    // 5. END SHIFT
    // =====================================================
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
            return response()->json([
                'message' => 'Tidak ada shift aktif'
            ], 404);
        }

        $cashSales = Payment::where('method', 'cash')
            ->where('paid_by', $shift->user_id)
            ->sum(DB::raw('amount_paid - change_amount'));

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
            'message' => 'Shift selesai',
            'data' => $shift
        ]);
    }

    // =====================================================
    // 6. CHECK STATUS
    // =====================================================
    public function checkStatus(Request $request)
    {
        $active = ShiftKaryawan::where('user_id', auth()->id())
            ->where('status', 'active')
            ->first();

        if (!$active) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada shift aktif'
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $active->load('shift', 'outlet')
        ]);
    }

    // =====================================================
    // 7. VERIFIKASI MANAGER
    // =====================================================
    public function resolveAutoClose(Request $request, $id)
    {
        $validated = $request->validate([
            'actual_closing_balance' => 'required|integer|min:0',
        ]);

        $shift = ShiftKaryawan::findOrFail($id);

        $difference = $validated['actual_closing_balance']
            - $shift->closing_balance_system;

        $shift->update([
            'closing_balance_actual' => $validated['actual_closing_balance'],
            'difference' => $difference,
        ]);

        return response()->json([
            'message' => 'Berhasil diverifikasi',
            'data' => $shift
        ]);
    }
}
