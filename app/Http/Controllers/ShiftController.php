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
            $query = Shift::with('users:id,name');

            // ==========================================
            // 1. FILTER RBAC (ROLE-BASED ACCESS CONTROL)
            // ==========================================
            if ($user->role === 'manager') {
                // Manager hanya boleh melihat jadwal di outlet miliknya
                $outletIds = Outlet::where('user_id', $user->id)
                                   ->orWhere('owner_id', $user->id)
                                   ->pluck('id');

                $query->whereIn('outlet_id', $outletIds);
            } elseif ($user->role === 'karyawan') {
                // Karyawan hanya melihat jadwal di tempat dia ditempatkan
                $query->where('outlet_id', $user->outlet_id);
            }
            // Jika Developer, lewati filter ini (Bisa melihat semua jadwal)

            // ==========================================
            // 2. FILTER DROPDOWN OUTLET VUE
            // ==========================================
            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->outlet_id);
            }

            return response()->json([
                'data' => $query->latest()->get()
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Database error.', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'outlet_id'  => 'required|exists:outlets,id',
            'name'       => 'required|string',
            'start_time' => 'required',
            'end_time'   => 'required',
            'user_ids'   => 'nullable|array',
        ]);

        // Cek Keamanan: Pastikan Manager tidak bisa membuat jadwal untuk Outlet orang lain
        $user = auth()->user();
        if ($user->role === 'manager') {
            $isOwner = Outlet::where('id', $request->outlet_id)
                             ->where(function($q) use ($user) {
                                 $q->where('user_id', $user->id)->orWhere('owner_id', $user->id);
                             })->exists();
            if (!$isOwner) {
                return response()->json(['message' => 'Anda tidak memiliki akses ke outlet ini.'], 403);
            }
        }

        DB::beginTransaction();
        try {
            $shift = Shift::create($request->only('outlet_id', 'name', 'start_time', 'end_time'));

            if ($request->has('user_ids') && is_array($request->user_ids)) {
                $shift->users()->sync($request->user_ids);
            }

            DB::commit();
            return response()->json(['message' => 'Jadwal Shift berhasil dibuat', 'data' => $shift], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal menyimpan', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'outlet_id'  => 'required|exists:outlets,id',
            'name'       => 'required|string',
            'start_time' => 'required',
            'end_time'   => 'required',
            'user_ids'   => 'nullable|array',
        ]);

        $shift = Shift::findOrFail($id);

        // Cek Keamanan RBAC
        $user = auth()->user();
        if ($user->role === 'manager') {
            $isOwner = Outlet::where('id', $shift->outlet_id)
                             ->where(function($q) use ($user) {
                                 $q->where('user_id', $user->id)->orWhere('owner_id', $user->id);
                             })->exists();
            if (!$isOwner) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
        }

        DB::beginTransaction();
        try {
            $shift->update($request->only('outlet_id', 'name', 'start_time', 'end_time'));

            if ($request->has('user_ids') && is_array($request->user_ids)) {
                $shift->users()->sync($request->user_ids);
            } else {
                $shift->users()->detach();
            }

            DB::commit();
            return response()->json(['message' => 'Jadwal Shift berhasil diperbarui']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal update', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $shift = Shift::findOrFail($id);

        // Cek Keamanan RBAC
        $user = auth()->user();
        if ($user->role === 'manager') {
            $isOwner = Outlet::where('id', $shift->outlet_id)
                        ->where(function($q) use ($user) {
                            $q->where('user_id', $user->id)->orWhere('owner_id', $user->id);
                        })->exists();
            if (!$isOwner) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
        }

        $shift->delete();
        return response()->json(['message' => 'Jadwal Shift berhasil dihapus']);
    }
}
