<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $query = Shift::with(['users:id,name', 'outlet:id,name']);

            // --- PROTEKSI RBAC ---
            if ($user->role === 'manager') {
                // Manager hanya boleh melihat jadwal di outlet yang ia miliki
                $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');
                $query->whereIn('outlet_id', $outletIds);
            } elseif ($user->role === 'karyawan') {
                // Karyawan hanya melihat jadwal di outlet tempat ia bekerja
                $query->where('outlet_id', $user->outlet_id);
            }
            // Developer melihat semuanya (tanpa filter)

            // Filter tambahan dari dropdown Vue
            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->outlet_id);
            }

            $shifts = $query->latest()->get();

            return response()->json([
                'data' => $shifts
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal mengambil data shift',
                'error'   => $e->getMessage()
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
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $user = auth()->user();
        
        // --- PROTEKSI KEAMANAN ---
        // Cek apakah outlet_id yang dikirim benar-benar milik manager ini
        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $validated['outlet_id'])
                            ->where('owner_id', $user->id)
                            ->exists();
            if (!$isMine) {
                return response()->json(['message' => 'Akses ditolak. Outlet ini bukan milik Anda.'], 403);
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

            $shift->users()->sync($request->input('user_ids', []));

            DB::commit();
            $shift->load(['users:id,name', 'outlet:id,name']);

            return response()->json([
                'message' => 'Jadwal Shift berhasil dibuat',
                'data'    => $shift
            ], 201);

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

        // --- PROTEKSI KEAMANAN ---
        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $shift->outlet_id)
                            ->where('owner_id', $user->id)
                            ->exists();
            if (!$isMine) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
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

            return response()->json([
                'message' => 'Jadwal Shift berhasil diperbarui',
                'data'    => $shift
            ], 200);

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

            // --- PROTEKSI KEAMANAN ---
            if ($user->role === 'manager') {
                $isMine = Outlet::where('id', $shift->outlet_id)
                                ->where('owner_id', $user->id)
                                ->exists();
                if (!$isMine) {
                    return response()->json(['message' => 'Akses ditolak.'], 403);
                }
            }

            $shift->users()->detach();
            $shift->delete();

            return response()->json(['message' => 'Jadwal Shift berhasil dihapus'], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal menghapus', 'error' => $e->getMessage()], 500);
        }
    }
}