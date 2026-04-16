<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    // Tampilkan daftar jadwal shift beserta karyawannya
    public function index(Request $request)
    {
        try {
            $query = Shift::with('users:id,name');

            // BUG FIX: Gunakan 'filled', bukan 'has'. Agar opsi "Semua Outlet" di Vue berfungsi.
            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->outlet_id);
            }

            $shifts = $query->get();

            return response()->json(['data' => $shifts]);

        } catch (\Exception $e) {
            // Mencegah Error 500 mematikan aplikasi frontend
            return response()->json([
                'message' => 'Gagal mengambil data. Pastikan tabel shift_user sudah di-migrate.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Manager membuat jadwal shift baru
    public function store(Request $request)
    {
        $request->validate([
            'outlet_id'  => 'required|exists:outlets,id',
            'name'       => 'required|string',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
            'user_ids'   => 'nullable|array', // Array ID Karyawan yang di-assign
            'user_ids.*' => 'exists:users,id'
        ]);

        try {
            // Buat Master Shift
            $shift = Shift::create($request->only('outlet_id', 'name', 'start_time', 'end_time'));

            // Assign karyawan ke dalam shift tersebut secara otomatis!
            if ($request->has('user_ids')) {
                $shift->users()->sync($request->user_ids);
            }

            return response()->json([
                'message' => 'Jadwal Shift berhasil dibuat',
                'data' => $shift->load('users:id,name')
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menyimpan', 'error' => $e->getMessage()], 500);
        }
    }

    // Manager update shift (Misal: ganti jam atau tambah/kurangi karyawan)
    public function update(Request $request, Shift $shift)
    {
        $validated = $request->validate([
            'name'       => 'sometimes|string',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time'   => 'sometimes|date_format:H:i',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'exists:users,id'
        ]);

        try {
            $shift->update($request->only('name', 'start_time', 'end_time'));

            if ($request->has('user_ids')) {
                // sync() otomatis menghapus karyawan yang uncheck dan menambah yang dicentang
                $shift->users()->sync($request->user_ids);
            } else {
                // Jika manager menghapus semua checklist karyawan
                $shift->users()->detach();
            }

            return response()->json([
                'message' => 'Jadwal Shift berhasil diupdate'
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal update', 'error' => $e->getMessage()], 500);
        }
    }

    // Manager hapus shift
    public function destroy(Shift $shift)
    {
        try {
            // Lepaskan semua relasi user dulu (biar aman dari foreign key constraint)
            $shift->users()->detach();

            // Hapus shift
            $shift->delete();

            return response()->json([
                'message' => 'Jadwal Shift berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal hapus', 'error' => $e->getMessage()], 500);
        }
    }
}
