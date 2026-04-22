<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'outlet_id' => 'sometimes|exists:outlets,id'
        ]);

        $user = auth()->user();
        $query = Schedule::with(['shift:id,name,start_time,end_time,color,outlet_id', 'user:id,name', 'outlet:id,name'])
            ->forDateRange($validated['start_date'], $validated['end_date']);

        if (isset($validated['outlet_id'])) {
            $query->forOutlet($validated['outlet_id']);
        } elseif ($user->role === 'manager') {
            $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds);
        } elseif ($user->role === 'karyawan') {
            $query->where('user_id', $user->id);
        }

        $schedules = $query->get();

        return response()->json([
            'data' => $schedules,
            'period' => [
                'start' => $validated['start_date'],
                'end' => $validated['end_date']
            ]
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'shift_id' => 'required|exists:shifts,id',
            'date' => 'required|date',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id'
        ]);

        $user = auth()->user();
        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $validated['outlet_id'])->where('owner_id', $user->id)->exists();
            if (!$isMine) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
        }

        $invalidUsers = User::whereIn('id', $validated['user_ids'])
            ->where(function ($q) use ($validated) {
                $q->where('role', '!=', 'karyawan')->orWhere('outlet_id', '!=', $validated['outlet_id']);
            })->exists();

        if ($invalidUsers) {
            return response()->json(['message' => 'Hanya karyawan outlet ini yang boleh ditugaskan.'], 422);
        }

        $conflictCount = Schedule::where('outlet_id', $validated['outlet_id'])
            ->where('date', $validated['date'])
            ->whereIn('user_id', $validated['user_ids'])
            ->exists();

        if ($conflictCount) {
            return response()->json(['message' => 'Karyawan sudah memiliki jadwal lain di tanggal ini.'], 400);
        }

        DB::beginTransaction();
        try {
            $createdSchedules = [];
            foreach ($validated['user_ids'] as $userId) {
                $schedule = Schedule::create([
                    'outlet_id' => $validated['outlet_id'],
                    'shift_id' => $validated['shift_id'],
                    'user_id' => $userId,
                    'date' => $validated['date']
                ]);
                $createdSchedules[] = $schedule->load('shift:id,name,start_time,end_time,color', 'user:id,name');
            }

            DB::commit();
            return response()->json([
                'message' => 'Jadwal berhasil ditetapkan',
                'data' => $createdSchedules
            ], 201);
        } catch (\Throwable $e) {
            DB::rollback();
            return response()->json(['message' => 'Gagal menyimpan jadwal', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $schedule = Schedule::findOrFail($id);
        $user = auth()->user();

        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $schedule->outlet_id)->where('owner_id', $user->id)->exists();
            if (!$isMine) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
        } elseif ($user->role === 'karyawan' && $user->id !== $schedule->user_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $schedule->delete();

        return response()->json(['message' => 'Jadwal dihapus'], 200);
    }
}
