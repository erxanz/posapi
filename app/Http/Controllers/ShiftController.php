<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ShiftController extends Controller
{
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

            $shifts = $query->latest()->get();

            return response()->json(['data' => $shifts], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal mengambil data', 'error' => $e->getMessage()], 500);
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
            $isMine = Outlet::where('id', $validated['outlet_id'])->where('owner_id', $user->id)->exists();
            if (!$isMine) return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        DB::beginTransaction();
        try {
            $shift = Shift::create([
                'outlet_id'  => $validated['outlet_id'],
                'name'       => $validated['name'],
                'start_time' => $validated['start_time'],
                'end_time'   => $validated['end_time'],
                'color'      => $validated['color'] ?? null,
            ]);

            DB::commit();
            $shift->load(['outlet:id,name']);

            return response()->json(['message' => 'Master shift berhasil dibuat (tugaskan karyawan via /schedules)', 'data' => $shift], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal menyimpan', 'error' => $e->getMessage()], 500);
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
        $user = auth()->user();

        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $shift->outlet_id)->where('owner_id', $user->id)->exists();
            if (!$isMine) return response()->json(['message' => 'Akses ditolak.'], 403);

            $isTargetOutletMine = Outlet::where('id', $validated['outlet_id'])->where('owner_id', $user->id)->exists();
            if (!$isTargetOutletMine) return response()->json(['message' => 'Akses ditolak untuk outlet tujuan.'], 403);
        }

        DB::beginTransaction();
        try {
            $shift->update([
                'outlet_id'  => $validated['outlet_id'],
                'name'       => $validated['name'],
                'start_time' => $validated['start_time'],
                'end_time'   => $validated['end_time'],
                'color'      => $validated['color'] ?? null,
            ]);

            DB::commit();
            $shift->load(['outlet:id,name']);

            return response()->json(['message' => 'Jadwal diperbarui', 'data' => $shift], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal update', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $shift = Shift::findOrFail($id);
            $user = auth()->user();

            if ($user->role === 'manager') {
                $isMine = Outlet::where('id', $shift->outlet_id)->where('owner_id', $user->id)->exists();
                if (!$isMine) return response()->json(['message' => 'Akses ditolak.'], 403);
            }

            $shift->delete();

            return response()->json(['message' => 'Jadwal dihapus'], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal menghapus', 'error' => $e->getMessage()], 500);
        }
    }

    public function autoGenerate(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'month'     => 'nullable|integer|min:1|max:12',
            'year'      => 'nullable|integer'
        ]);

        $user = auth()->user();
        $outletId = $validated['outlet_id'];

        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $outletId)->where('owner_id', $user->id)->exists();
            if (!$isMine) return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $shifts = Shift::where('outlet_id', $outletId)->get();
        if ($shifts->isEmpty()) {
            return response()->json(['message' => 'Tidak ada master shift. Buat minimal 1 shift dulu.'], 400);
        }

        $karyawans = User::where('outlet_id', $outletId)->where('role', 'karyawan')->pluck('id')->toArray();
        if (empty($karyawans)) {
            return response()->json(['message' => 'Tidak ada karyawan di outlet ini.'], 400);
        }

        DB::beginTransaction();
        try {
            $targetDate = now();
            if ($request->filled('month') && $request->filled('year')) {
                $targetDate = Carbon::create($validated['year'], $validated['month'], 1);
            }

            $startOfMonth = $targetDate->copy()->startOfMonth();
            $endOfMonth = $targetDate->copy()->endOfMonth();

            Schedule::where('outlet_id', $outletId)
                ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                ->delete();

            $newSchedules = [];
            $shiftCount = $shifts->count();
            // Menghitung berapa banyak karyawan yang ditugaskan per 1 shift
            $empPerShift = ceil(count($karyawans) / $shiftCount);

            for ($date = $startOfMonth->copy(); $date->lte($endOfMonth); $date->addDay()) {

                // Ambil semua karyawan lalu acak posisinya setiap hari berganti
                $dailyKaryawans = $karyawans;
                shuffle($dailyKaryawans);

                // Pecah karyawan menjadi beberapa grup sesuai jumlah shift
                $chunks = array_chunk($dailyKaryawans, $empPerShift);

                foreach ($shifts as $index => $shift) {
                    // Ambil grup karyawan berdasarkan index shift (jika grupnya ada)
                    $assignedUsers = $chunks[$index] ?? [];

                    foreach ($assignedUsers as $userId) {
                        $newSchedules[] = [
                            'outlet_id'  => $outletId,
                            'shift_id'   => $shift->id,
                            'user_id'    => $userId,
                            'date'       => $date->toDateString(),
                            'created_at' => now()->toDateTimeString(),
                            'updated_at' => now()->toDateTimeString(),
                        ];
                    }
                }
            }

            // Insert massal
            foreach (array_chunk($newSchedules, 100) as $chunk) {
                Schedule::insert($chunk);
            }

            DB::commit();
            return response()->json(['message' => 'Jadwal bulan ini berhasil digenerate otomatis secara acak.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal auto-generate', 'error' => $e->getMessage()], 500);
        }
    }

    public function mySchedule(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'karyawan') {
            return response()->json([
                'message' => 'Endpoint ini khusus karyawan.',
                'data' => []
            ], 403);
        }

        $todaySchedules = Schedule::where('user_id', $user->id)
            ->where('date', now()->toDateString())
            ->with('shift.outlet:id,name', 'shift:id,name,start_time,end_time,color')
            ->get()
            ->pluck('shift', 'shift.id')
            ->values();

        if ($todaySchedules->isEmpty()) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal shift hari ini.',
                'data' => []
            ], 200);
        }

        return response()->json([
            'message' => 'Berhasil mengambil jadwal saya hari ini',
            'data' => $todaySchedules
        ], 200);
    }
}
