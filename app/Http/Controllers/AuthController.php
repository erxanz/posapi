<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * REGISTER (Manager)
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
            'role' => 'manager'
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil',
            'token' => $token,
            'user' => $user
        ], 201);
    }

    /**
     * LOGIN EMAIL (MANAGER & DEVELOPER)
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', strtolower($request->email))->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Email atau password salah'
            ], 401);
        }

        // WAJIB CEK AKTIF (karena ini khusus karyawan)
        if ($user->role === 'karyawan' && !$user->is_active) {
            return response()->json([
                'message' => 'Akun karyawan tidak aktif'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => $user->load('outlet')
        ]);
    }

    /**
     * LOGIN PIN (KARYAWAN)
     */
    public function loginPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|digits:6',
            'outlet_id' => 'required|exists:outlets,id'
        ]);

        $user = User::where('pin', $request->pin)
            ->where('outlet_id', $request->outlet_id)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'PIN salah'
            ], 401);
        }

        // WAJIB CEK AKTIF (karena ini khusus karyawan)
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Akun karyawan tidak aktif'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => $user->load('outlet')
        ]);
    }

    /**
     * GET USER LOGIN
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('outlet')
        ]);
    }

    /**
     * LOGOUT (CURRENT DEVICE ONLY)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil'
        ]);
    }
}
