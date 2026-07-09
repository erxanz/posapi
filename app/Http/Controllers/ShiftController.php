<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Schedule;
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

        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan mengelola shift.'], 403);
        }

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

        $user = auth()->user();

        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan mengelola shift.'], 403);
        }

        $shift = Shift::findOrFail($id);

        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $shift->outlet_id)->where('owner_id', $user->id)->exists();
            if (!$isMine) {
                return response()->json(['message' => 'Akses ditolak'], 403);
            }
        }

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
        $user = auth()->user();

        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan menghapus shift.'], 403);
        }

        $shift = Shift::findOrFail($id);

        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $shift->outlet_id)->where('owner_id', $user->id)->exists();
            if (!$isMine) {
                return response()->json(['message' => 'Akses ditolak'], 403);
            }
        }

        try {
            $shift->delete();

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
}
