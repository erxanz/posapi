<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    // Mengambil data Shift aktif berdasarkan jam saat ini
    private function getActiveShift(User $user, int $outletId)
    {
        $currentTime = now()->format('H:i:s');

        return $user->shifts()
            ->where('outlet_id', $outletId)
            ->where(function ($query) use ($currentTime) {
                $query
                    // Shift normal (contoh: 08:00-16:00)
                    ->where(function ($q) use ($currentTime) {
                        $q->whereColumn('start_time', '<=', 'end_time')
                            ->whereTime('start_time', '<=', $currentTime)
                            ->whereTime('end_time', '>=', $currentTime);
                    })
                    // Shift lintas tengah malam (contoh: 22:00-06:00)
                    ->orWhere(function ($q) use ($currentTime) {
                        $q->whereColumn('start_time', '>', 'end_time')
                            ->where(function ($q2) use ($currentTime) {
                                $q2->whereTime('start_time', '<=', $currentTime)
                                    ->orWhereTime('end_time', '>=', $currentTime);
                            });
                    });
            })
            ->first();
    }

    /**
     * REGISTER (Manager)
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
            'image' => $this->storeImageIfUploaded($request),
            'phone_number' => $request->phone_number,
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
     * LOGIN EMAIL (MANAGER, DEVELOPER, & WEB KARYAWAN)
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

        $shiftId = null;

        // Cek khusus karyawan
        if ($user->role === 'karyawan') {
            if (!$user->is_active) {
                return response()->json([
                    'message' => 'Akun karyawan tidak aktif'
                ], 403);
            }

            $activeShift = $this->getActiveShift($user, (int) $user->outlet_id);

            // PERBAIKAN: Tidak diblokir dengan 403.
            // Tetap izinkan login agar bisa akses /my-schedule di Flutter.
            if ($activeShift) {
                $shiftId = $activeShift->id;
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'shift_id' => $shiftId,
            'user' => $user->load('outlet')
        ]);
    }

    /**
     * LOGIN PIN (KARYAWAN - FLUTTER)
     */
    public function loginPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|digits:6',
            'outlet_id' => 'required|exists:outlets,id'
        ]);

        $user = User::where('pin', $request->pin)
            ->where('outlet_id', $request->outlet_id)
            ->where('role', 'karyawan')
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'PIN salah atau karyawan tidak terdaftar di outlet ini.'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Akun karyawan tidak aktif'
            ], 403);
        }

        $activeShift = $this->getActiveShift($user, (int) $request->outlet_id);

        // PERBAIKAN: Karyawan tetap diizinkan login walaupun belum jam shift-nya.
        // Jika belum jam shift, shift_id akan dikirim null.
        $shiftId = $activeShift ? $activeShift->id : null;

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'shift_id' => $shiftId,
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
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
        ]);

        $user->name = $request->name;
        $user->email = strtolower($request->email);
        if ($request->hasFile('image')) {
            if ($user->image) {
                Storage::disk('public')->delete($user->image);
            }

            $user->image = $this->storeImageIfUploaded($request);
        }
        $user->phone_number = $request->phone_number;

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

        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $resetLink = "$frontendUrl/reset-password?token=$token&email=" . urlencode($user->email);

        $emailBody = view('emails.password-reset', compact('user', 'resetLink'))->render();

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

        if (!Hash::check($request->token, $record->token)) {
            return response()->json([
                'message' => 'Token tidak valid'
            ], 400);
        }

        if (Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            return response()->json([
                'message' => 'Token sudah kadaluarsa'
            ], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'User tidak ditemukan'
            ], 404);
        }

        if (!in_array($user->role, ['manager', 'developer'])) {
            return response()->json([
                'message' => 'Hanya untuk manager/developer'
            ], 403);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

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

    private function storeImageIfUploaded(Request $request): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        return $request->file('image')->store('users', 'public');
    }
}
