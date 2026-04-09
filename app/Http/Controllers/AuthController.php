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
     * Update user profile.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $user->name = $request->name;
        $user->email = $request->email;

        // Update password hanya jika form password diisi
        if ($request->filled('password')) {
            $user->password = \Illuminate\Support\Facades\Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'user' => $user
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

        $emailBody = "
            <!DOCTYPE html>
            <html lang='id'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; }
                    .header h1 { margin: 0; font-size: 28px; font-weight: 600; }
                    .content { padding: 30px 25px; }
                    .content h2 { color: #333; font-size: 22px; margin-top: 0; margin-bottom: 15px; }
                    .content p { color: #555; font-size: 16px; line-height: 1.6; margin: 10px 0; }
                    .button-container { text-align: center; margin: 30px 0; }
                    .btn { display: inline-block; padding: 12px 35px; background-color: #667eea; color: white; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px; }
                    .btn:hover { background-color: #5568d3; }
                    .info-box { background-color: #f9f9f9; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
                    .info-box strong { color: #333; }
                    .footer { background-color: #f5f5f5; padding: 20px 25px; text-align: center; border-top: 1px solid #e0e0e0; }
                    .footer p { color: #999; font-size: 13px; margin: 5px 0; }
                    .divider { height: 1px; background-color: #e0e0e0; margin: 25px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Reset Password</h1>
                    </div>
                    <div class='content'>
                        <h2>Halo, $user->name</h2>
                        <p>Kami menerima permintaan untuk mereset password akun Anda. Klik tombol di bawah untuk melanjutkan proses reset password:</p>

                        <div class='button-container'>
                            <a href='$resetLink' class='btn'>Reset Password</a>
                        </div>

                        <p>Atau salin dan tempel link berikut ke browser Anda:</p>
                        <p style='word-break: break-all; background-color: #f9f9f9; padding: 10px; border-radius: 4px; font-size: 13px; color: #666;'>$resetLink</p>

                        <div class='info-box'>
                            <strong>⏱️ Penting:</strong> Link reset password ini hanya berlaku selama <strong>15 menit</strong>. Jika Anda tidak mereset password dalam waktu tersebut, Anda harus meminta link baru.
                        </div>

                        <div class='divider'></div>

                        <p style='color: #888; font-size: 14px;'><strong>Keamanan:</strong> Jika Anda tidak meminta reset password ini, abaikan email ini dan hubungi tim support kami segera.</p>
                    </div>
                    <div class='footer'>
                        <p>© " . date('Y') . " POS API. Semua hak dilindungi.</p>
                        <p>Email ini dikirim ke <strong>$user->email</strong></p>
                        <p style='font-size: 12px; color: #bbb;'>Mohon jangan membalas email ini, karena mailbox ini tidak dimonitor.</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        Mail::html($emailBody, function ($message) use ($user) {
            $message->to($user->email)
                    ->subject('Reset Password - POS API');
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
