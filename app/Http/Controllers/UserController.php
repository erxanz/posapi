<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\User;

class UserController extends Controller
{
    /**
     * CREATE KARYAWAN (MANAGER)
     */
    public function createKaryawan(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'pin' => [
                'required',
                'digits:6',
                Rule::unique('users')->where(function ($q) use ($user) {
                    return $q->where('outlet_id', $user->outlet_id);
                })
            ]
        ]);

        $karyawan = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),

            // TANPA HASH
            'pin' => $request->pin,

            'role' => 'karyawan',
            'outlet_id' => $user->outlet_id
        ]);

        return response()->json([
            'message' => 'Karyawan berhasil dibuat',
            'data' => $karyawan
        ], 201);
    }

    /**
     * LIST KARYAWAN
     */
    public function listKaryawan()
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->select('id', 'name', 'email', 'role', 'is_active', 'pin')
            ->latest()
            ->get();

        return response()->json(['data' => $karyawan]);
    }

    /**
     * SHOW KARYAWAN
     */
    public function showKaryawan($id)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->select('id', 'name', 'email', 'role', 'is_active', 'pin')
            ->findOrFail($id);

        return response()->json(['data' => $karyawan]);
    }

    /**
     * UPDATE KARYAWAN
     */
    public function updateKaryawan(Request $request, $id)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($id)
            ],
            'password' => 'nullable|min:6',
            'pin' => [
                'nullable',
                'digits:6',
                Rule::unique('users')->where(function ($q) use ($user) {
                    return $q->where('outlet_id', $user->outlet_id);
                })->ignore($id)
            ],
            'is_active' => 'boolean'
        ]);

        $karyawan->name = $request->name;
        $karyawan->email = strtolower($request->email);

        if ($request->password) {
            $karyawan->password = Hash::make($request->password);
        }

        if ($request->pin) {
            // TANPA HASH
            $karyawan->pin = $request->pin;
        }

        if (!is_null($request->is_active)) {
            $karyawan->is_active = $request->is_active;
        }

        $karyawan->save();

        return response()->json([
            'message' => 'Karyawan berhasil diupdate',
            'data' => $karyawan
        ]);
    }

    /**
     * DELETE KARYAWAN
     */
    public function deleteKaryawan($id)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $karyawan = User::where('outlet_id', $user->outlet_id)
            ->where('role', 'karyawan')
            ->findOrFail($id);

        $karyawan->delete();

        return response()->json([
            'message' => 'Karyawan berhasil dihapus'
        ]);
    }

    /**
     * ===============================
     * DEVELOPER SECTION
     * ===============================
     */

    public function listUsers()
    {
        $this->authorizeDeveloper();

        return response()->json([
            'data' => User::latest()->get()
        ]);
    }

    public function createUser(Request $request)
    {
        $this->authorizeDeveloper();

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'pin' => 'nullable|digits:6',
            'role' => 'required|in:developer,manager,karyawan',
            'outlet_id' => 'nullable|exists:outlets,id'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
            'pin' => $request->pin, // TANPA HASH
            'role' => $request->role,
            'outlet_id' => $request->outlet_id
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat',
            'data' => $user
        ], 201);
    }

    public function deleteUser($id)
    {
        $this->authorizeDeveloper();

        User::findOrFail($id)->delete();

        return response()->json([
            'message' => 'User berhasil dihapus'
        ]);
    }

    /**
     * HELPER
     */
    private function authorizeDeveloper()
    {
        if (auth()->user()->role !== 'developer') {
            abort(403, 'Akses ditolak');
        }
    }
}
