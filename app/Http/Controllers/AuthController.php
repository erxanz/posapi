<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

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
     * FORGOT PASSWORD
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $user = User::where('email', strtolower($request->email))->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email tidak ditemukan'
            ], 404);
        }

        if (!in_array($user->role, ['manager', 'developer'])) {
            return response()->json([
                'message' => 'Hanya untuk manager/developer'
            ], 403);
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now()
            ]
        );

        // Link reset diarahkan ke frontend (bukan API/backend).
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $resetLink = "$frontendUrl/reset-password?token=$token&email=" . urlencode($user->email);

        Mail::html("
            <h2>Reset Password</h2>
            <p>Klik tombol di bawah untuk reset password:</p>
            <a href='$resetLink' style='padding:10px 15px;background:#4CAF50;color:white;text-decoration:none;'>Reset Password</a>
            <p>Link berlaku 15 menit</p>
            ", function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Reset Password');
        });

        return response()->json([
            'message' => 'Link reset password sudah dikirim ke email'
        ]);
    }

    /**
     * RESET PASSWORD
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:6|confirmed'
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record) {
            return response()->json([
                'message' => 'Token tidak ditemukan'
            ], 400);
        }

        // cek token
        if (!Hash::check($request->token, $record->token)) {
            return response()->json([
                'message' => 'Token tidak valid'
            ], 400);
        }

        // cek expired
        if (Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            return response()->json([
                'message' => 'Token sudah kadaluarsa'
            ], 400);
        }

        // pastikan user ada
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        // cek role (hanya manager/developer)
        if (!in_array($user->role, ['manager', 'developer'])) {
            return response()->json([
                'message' => 'Hanya untuk manager/developer'
            ], 403);
        }

        // update password
        $user->update([
            'password' => Hash::make($request->password)
        ]);

        // hapus token
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'message' => 'Password berhasil direset'
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
