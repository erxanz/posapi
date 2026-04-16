<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Shift::with(['users:id,name', 'outlet:id,name']);

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
            'end_time'   => 'required|date_format:H:i|after:start_time',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

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

            // Load relasi biar langsung kepakai di Vue
            $shift->load(['users:id,name', 'outlet:id,name']);

            return response()->json([
                'message' => 'Jadwal Shift berhasil dibuat',
                'data'    => $shift
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal menyimpan',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'outlet_id'  => 'required|exists:outlets,id',
            'name'       => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $shift = Shift::findOrFail($id);

        DB::beginTransaction();
        try {
            $shift->update([
                'outlet_id'  => $validated['outlet_id'],
                'name'       => $validated['name'],
                'start_time' => $validated['start_time'],
                'end_time'   => $validated['end_time'],
            ]);

            // Fix utama: tidak perlu detach manual lagi
            $shift->users()->sync($request->input('user_ids', []));

            DB::commit();

            $shift->load(['users:id,name', 'outlet:id,name']);

            return response()->json([
                'message' => 'Jadwal Shift berhasil diperbarui',
                'data'    => $shift
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal update',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $shift = Shift::findOrFail($id);
            $shift->users()->detach(); // optional tapi aman
            $shift->delete();

            return response()->json([
                'message' => 'Jadwal Shift berhasil dihapus'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal menghapus',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
