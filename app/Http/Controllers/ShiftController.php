<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $query = Shift::with(['users:id,name', 'outlet:id,name']);

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
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $user = auth()->user();
        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $validated['outlet_id'])->where('owner_id', $user->id)->exists();
            if (!$isMine) return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if (!empty($validated['user_ids'])) {
            $invalidUsersCount = User::whereIn('id', $validated['user_ids'])
                ->where(function ($query) use ($validated) {
                    $query->where('role', '!=', 'karyawan')
                        ->orWhere('outlet_id', '!=', $validated['outlet_id']);
                })
                ->count();

            if ($invalidUsersCount > 0) {
                return response()->json([
                    'message' => 'Hanya user role karyawan pada outlet yang sama yang boleh ditugaskan ke shift.'
                ], 422);
            }
        }

        // --- CEGAK DOUBLE SHIFT ---
        if (!empty($validated['user_ids'])) {
            $hasOtherShift = DB::table('shift_user')
                ->join('shifts', 'shift_user.shift_id', '=', 'shifts.id')
                ->whereIn('shift_user.user_id', $validated['user_ids'])
                ->where('shifts.outlet_id', $validated['outlet_id'])
                ->exists();

            if ($hasOtherShift) {
                return response()->json(['message' => 'Gagal: Salah satu karyawan sudah memiliki jadwal shift.'], 400);
            }
        }

        DB::beginTransaction();
        try {
            $shift = Shift::create([
                'outlet_id'  => $validated['outlet_id'],
                'name'       => $validated['name'],
                'start_time' => $validated['start_time'],
                'end_time'   => $validated['end_time'],
            ]);

            if (!empty($validated['user_ids'])) {
                $shift->users()->sync($validated['user_ids']);
            }

            DB::commit();
            $shift->load(['users:id,name', 'outlet:id,name']);

            return response()->json(['message' => 'Jadwal berhasil dibuat', 'data' => $shift], 201);
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
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $shift = Shift::findOrFail($id);
        $user = auth()->user();

        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $shift->outlet_id)->where('owner_id', $user->id)->exists();
            if (!$isMine) return response()->json(['message' => 'Akses ditolak.'], 403);

            $isTargetOutletMine = Outlet::where('id', $validated['outlet_id'])->where('owner_id', $user->id)->exists();
            if (!$isTargetOutletMine) return response()->json(['message' => 'Akses ditolak untuk outlet tujuan.'], 403);
        }

        if (!empty($validated['user_ids'])) {
            $invalidUsersCount = User::whereIn('id', $validated['user_ids'])
                ->where(function ($query) use ($validated) {
                    $query->where('role', '!=', 'karyawan')
                        ->orWhere('outlet_id', '!=', $validated['outlet_id']);
                })
                ->count();

            if ($invalidUsersCount > 0) {
                return response()->json([
                    'message' => 'Hanya user role karyawan pada outlet yang sama yang boleh ditugaskan ke shift.'
                ], 422);
            }
        }

        // --- CEGAK DOUBLE SHIFT (Kecuali di shift yang sedang di-edit) ---
        if (!empty($validated['user_ids'])) {
            $hasOtherShift = DB::table('shift_user')
                ->join('shifts', 'shift_user.shift_id', '=', 'shifts.id')
                ->whereIn('shift_user.user_id', $validated['user_ids'])
                ->where('shifts.outlet_id', $validated['outlet_id'])
                ->where('shift_user.shift_id', '!=', $id) // Abaikan shift ini sendiri
                ->exists();

            if ($hasOtherShift) {
                return response()->json(['message' => 'Gagal: Salah satu karyawan sudah memiliki jadwal di shift lain.'], 400);
            }
        }

        DB::beginTransaction();
        try {
            $shift->update([
                'outlet_id'  => $validated['outlet_id'],
                'name'       => $validated['name'],
                'start_time' => $validated['start_time'],
                'end_time'   => $validated['end_time'],
            ]);

            $shift->users()->sync($request->input('user_ids', []));

            DB::commit();
            $shift->load(['users:id,name', 'outlet:id,name']);

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

            $shift->users()->detach();
            $shift->delete();

            return response()->json(['message' => 'Jadwal dihapus'], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal menghapus', 'error' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // FITUR BARU: GENERATE JADWAL OTOMATIS
    // ==========================================
    public function autoGenerate(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id'
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

        // Ambil semua karyawan yang bekerja di outlet ini
        $karyawans = User::where('outlet_id', $outletId)->where('role', 'karyawan')->pluck('id');
        if ($karyawans->isEmpty()) {
            return response()->json(['message' => 'Tidak ada karyawan di outlet ini.'], 400);
        }

        DB::beginTransaction();
        try {
            // 1. Reset jadwal lama di outlet ini
            $shiftIds = $shifts->pluck('id');
            DB::table('shift_user')->whereIn('shift_id', $shiftIds)->delete();

            // 2. Bagi rata karyawan ke masing-masing shift
            // Contoh: 5 Karyawan, 2 Shift. Maka Shift 1 dapat 3, Shift 2 dapat 2.
            $karyawans = $karyawans->shuffle(); // Acak agar adil
            $chunkSize = ceil($karyawans->count() / $shifts->count());
            $chunks = $karyawans->chunk($chunkSize)->values();

            foreach ($shifts as $index => $shift) {
                if (isset($chunks[$index])) {
                    $shift->users()->sync($chunks[$index]);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Jadwal berhasil digenerate otomatis secara merata.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal auto-generate', 'error' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // UNTUK APLIKASI KASIR (FLUTTER)
    // ==========================================
    public function mySchedule(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'karyawan') {
            return response()->json([
                'message' => 'Endpoint ini khusus karyawan.',
                'data' => []
            ], 403);
        }

        // Ambil jadwal shift yang ditugaskan kepada karyawan yang sedang login
        $myShifts = $user->shifts()->with('outlet:id,name')->get();

        if ($myShifts->isEmpty()) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal shift yang ditugaskan saat ini.',
                'data' => []
            ], 200);
        }

        return response()->json([
            'message' => 'Berhasil mengambil jadwal saya',
            'data' => $myShifts
        ], 200);
    }
}
