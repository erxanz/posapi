<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    // Tampilkan daftar jadwal shift beserta karyawannya
    public function index(Request $request)
    {
        $shifts = Shift::with('users:id,name')->where('outlet_id', $request->outlet_id)->get();
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

        return response()->json(['message' => 'Jadwal Shift berhasil dibuat', 'data' => $shift]);
    }

    // Manager update shift (Misal: ganti jam atau tambah/kurangi karyawan)
    public function update(Request $request, Shift $shift)
    {
        $shift->update($request->only('name', 'start_time', 'end_time'));

        if ($request->has('user_ids')) {
            // sync() otomatis menghapus karyawan yang uncheck dan menambah yang dicentang
            $shift->users()->sync($request->user_ids);
        }

        return response()->json(['message' => 'Jadwal Shift berhasil diupdate']);
    }
}

