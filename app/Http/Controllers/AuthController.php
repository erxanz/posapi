<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\Outlet;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * REGISTER
     */
    public function register(Request $request)
    {
        // 1. VALIDASI
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'outlet_name' => 'required|string|max:100'
        ]);

        // 2. TRANSACTION (WAJIB BEST PRACTICE)
        DB::beginTransaction();

        try {
            // 3. BUAT USER (MANAGER)
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'manager'
            ]);

            // 4. BUAT OUTLET
            $outlet = Outlet::create([
                'name' => $request->outlet_name,
                'owner_id' => $user->id
            ]);

            // 5. HUBUNGKAN USER KE OUTLET
            $user->update([
                'outlet_id' => $outlet->id
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Registrasi berhasil',
                'user' => $user,
                'outlet' => $outlet
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Registrasi gagal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * LOGIN
     */
    public function login(Request $request)
    {
        // validasi input
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // cek user
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Email atau password salah'
            ], 401);
        }

        // buat token sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => $user
        ], 200);
    }

    /**
     * Login PIN Karyawan (Kasir)
     */
    public function loginPin(Request $request)
    {
        // validasi input
        $request->validate([
            'pin' => 'required|digits:6'
        ]);

        // cek user berdasarkan PIN
        $user = User::where('pin', $request->pin)->first();

        if (!$user) {
            return response()->json([
                'message' => 'PIN salah'
            ], 401);
        }

        // buat token sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => $user
        ], 200);
    }

    /**
     * GET USER LOGIN
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('outlet') // load outlet relationship
        ], 200);
    }

    /**
     * LOGOUT
     */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logout berhasil'
        ], 200);
    }
}
