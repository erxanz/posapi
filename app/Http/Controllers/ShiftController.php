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
            $query = Shift::with('users:id,name');

            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->outlet_id);
            }

            return response()->json([
                'data' => $query->latest()->get()
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Database error: Pastikan tabel shift_user sudah di-migrate.', 'error' => $e->getMessage()], 500);
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

        DB::beginTransaction();
        try {
            $shift->update($request->only('outlet_id', 'name', 'start_time', 'end_time'));

            if ($request->has('user_ids') && is_array($request->user_ids)) {
                $shift->users()->sync($request->user_ids);
            } else {
                $shift->users()->detach(); // Kosongkan karyawan jika checkbox dihapus semua
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
        $shift->delete();
        return response()->json(['message' => 'Jadwal Shift berhasil dihapus']);
    }
}
