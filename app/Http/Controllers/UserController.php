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

    // ========================================================
    // MANAGER ENDPOINTS (KARYAWAN)
    // ========================================================

    public function listKaryawan()
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = \App\Models\User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->get();

        return response()->json(['data' => $karyawan]);
    }

    public function showKaryawan($id)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = \App\Models\User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->findOrFail($id);

        return response()->json(['data' => $karyawan]);
    }

    public function deleteKaryawan($id)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = \App\Models\User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->findOrFail($id);

        $karyawan->delete();

        return response()->json(['message' => 'Karyawan berhasil dihapus']);
    }

    // ========================================================
    // DEVELOPER ENDPOINTS (ALL USERS)
    // ========================================================

    public function createUser(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'developer') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'pin' => 'required|min:4|max:6',
            'role' => 'required|in:developer,manager,karyawan',
            'outlet_id' => 'nullable|exists:outlets,id'
        ]);

        $newUser = \App\Models\User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'pin' => bcrypt($request->pin),
            'role' => $request->role,
            'outlet_id' => $request->outlet_id
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat',
            'data' => $newUser
        ], 201);
    }

    public function listUsers()
    {
        $user = auth()->user();

        if ($user->role !== 'developer') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $users = \App\Models\User::all();

        return response()->json(['data' => $users]);
    }

    public function showUser($id)
    {
        $user = auth()->user();

        if ($user->role !== 'developer') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $targetUser = \App\Models\User::findOrFail($id);

        return response()->json(['data' => $targetUser]);
    }

    public function updateUser(Request $request, $id)
    {
        $user = auth()->user();

        if ($user->role !== 'developer') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $targetUser = \App\Models\User::findOrFail($id);

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => 'nullable|min:6',
            'pin' => 'nullable|min:4|max:6',
            'role' => 'required|in:developer,manager,karyawan',
            'outlet_id' => 'nullable|exists:outlets,id'
        ]);

        $targetUser->name = $request->name;
        $targetUser->email = $request->email;
        $targetUser->role = $request->role;
        $targetUser->outlet_id = $request->outlet_id;

        if ($request->password) {
            $targetUser->password = bcrypt($request->password);
        }

        if ($request->pin) {
            $targetUser->pin = bcrypt($request->pin);
        }

        $targetUser->save();

        return response()->json([
            'message' => 'User berhasil diupdate',
            'data' => $targetUser
        ]);
    }

    public function updateKaryawan(Request $request, $id)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = \App\Models\User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->findOrFail($id);

        // Validasi
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => 'nullable|min:6',
            'pin' => 'nullable|min:4|max:6'
        ]);

        // Update data
        $karyawan->name = $request->name;
        $karyawan->email = $request->email;

        // Kalau password diisi → update
        if ($request->password) {
            $karyawan->password = bcrypt($request->password);
        }

        // Kalau pin diisi → update
        if ($request->pin) {
            $karyawan->pin = bcrypt($request->pin);
        }

        $karyawan->save();

        return response()->json([
            'message' => 'Karyawan berhasil diupdate',
            'data' => $karyawan
        ]);
    }

    public function deleteUser($id)
    {
        $user = auth()->user();

        if ($user->role !== 'developer') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $targetUser = \App\Models\User::findOrFail($id);
        $targetUser->delete();

        return response()->json(['message' => 'User berhasil dihapus']);
    }
}
