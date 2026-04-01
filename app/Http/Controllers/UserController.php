<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserController extends Controller
{
    public function createKaryawan(Request $request)
    {
        $user = auth()->user(); // yang login (manager)

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        // 1. Validasi input
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'pin' => 'required|min:4|max:6'
        ]);

        // 2. Pastikan hanya manager yang bisa buat karyawan
        if ($user->role !== 'manager') {
            return response()->json([
                'message' => 'Hanya manager yang boleh tambah karyawan'
            ], 403);
        }

        // 3. Buat karyawan
        $karyawan = \App\Models\User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'pin' => bcrypt($request->pin),

            // INI PALING PENTING
            'role' => 'karyawan',
            'outlet_id' => $user->outlet_id
        ]);

        return response()->json([
            'message' => 'Karyawan berhasil dibuat',
            'data' => $karyawan
        ]);
    }
}
