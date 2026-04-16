<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    // Tampilkan daftar jadwal shift beserta karyawannya
    public function index(Request $request)
    {
        $query = Shift::with('users:id,name');

        if ($request->has('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        $shifts = $query->get();

        return response()->json(['data' => $shifts]);
    }

    // Manager membuat jadwal shift baru
    public function store(Request $request)
    {
        $request->validate([
            'outlet_id'  => 'required|exists:outlets,id',
            'name'       => 'required|string',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
            'user_ids'   => 'array', // Array ID Karyawan yang di-assign
            'user_ids.*' => 'exists:users,id'
        ]);

        // Buat Master Shift
        $shift = Shift::create($request->only('outlet_id', 'name', 'start_time', 'end_time'));

        // Assign karyawan ke dalam shift tersebut secara otomatis!
        if ($request->has('user_ids')) {
            $shift->users()->sync($request->user_ids);
        }

        return response()->json([
            'message' => 'Jadwal Shift berhasil dibuat',
            'data' => $shift->load('users:id,name')
        ]);
    }

    // Manager update shift (Misal: ganti jam atau tambah/kurangi karyawan)
    public function update(Request $request, Shift $shift)
    {
        $validated = $request->validate([
            'name'       => 'sometimes|string',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time'   => 'sometimes|date_format:H:i',
            'user_ids'   => 'sometimes|array',
            'user_ids.*' => 'exists:users,id'
        ]);

        $shift->update($validated);

        if ($request->has('user_ids')) {
            // sync() otomatis menghapus karyawan yang uncheck dan menambah yang dicentang
            $shift->users()->sync($request->user_ids);
        }

        return response()->json([
            'message' => 'Jadwal Shift berhasil diupdate'
        ]);
    }

    // Manager hapus shift
    public function destroy(Shift $shift)
    {
        // Lepaskan semua relasi user dulu (biar aman kalau pakai pivot table)
        $shift->users()->detach();

        // Hapus shift
        $shift->delete();

        return response()->json([
            'message' => 'Jadwal Shift berhasil dihapus'
        ]);
    }
}

