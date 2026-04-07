<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Outlet;

class UserController extends Controller
{
    // ===== KARYAWAN MANAGEMENT (Manager) =====

    /**
     * POST /users/karyawan
     * Manager create karyawan untuk outlet miliknya
     *
     * Validasi:
     * - Manager hanya bisa create di outlet yang dia own
     * - PIN harus unique per outlet
     */
    public function createKaryawan(Request $request)
    {
        $user = auth()->user();

        // SECURITY: Hanya manager dan developer
        if (!$user->isManager() && !$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya manager dan developer yang dapat membuat karyawan',
            ], 403);
        }

        // Validasi input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'pin' => 'required|digits:6',
            'outlet_id' => $user->isDeveloper() ? 'required|exists:outlets,id' : 'nullable',
        ]);

        // SECURITY: Manager hanya bisa assign ke outlet miliknya
        if ($user->isManager()) {
            $validated['outlet_id'] = $user->ownedOutlets()->pluck('id')->first();

            if (!$validated['outlet_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Manager tidak memiliki outlet',
                ], 422);
            }
        }

        // SECURITY: Verify outlet exists dan user dapat akses
        $outlet = Outlet::findOrFail($validated['outlet_id']);
        if (!$outlet->isOwnedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Anda tidak dapat membuat karyawan di outlet ini',
            ], 403);
        }

        // SECURITY: PIN unique per outlet
        $existingPin = User::where('outlet_id', $validated['outlet_id'])
            ->where('pin', $validated['pin'])
            ->exists();

        if ($existingPin) {
            return response()->json([
                'success' => false,
                'message' => 'PIN sudah digunakan di outlet ini',
            ], 422);
        }

        $karyawan = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'pin' => $validated['pin'],
            'role' => 'karyawan',
            'outlet_id' => $validated['outlet_id'],
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Karyawan berhasil dibuat',
            'data' => $karyawan->makeHidden(['password', 'remember_token', 'pin']),
        ], 201);
    }

    /**
     * GET /users/karyawan
     * Manager list karyawan di outlet miliknya
     */
    public function listKaryawan(Request $request)
    {
        $user = auth()->user();

        // SECURITY: Hanya manager dan developer
        if (!$user->isManager() && !$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $query = User::where('role', 'karyawan');

        // SECURITY: Manager hanya lihat karyawan di outlet miliknya
        if ($user->isManager()) {
            $outletIds = $user->ownedOutlets()->pluck('id')->toArray();
            $query->whereIn('outlet_id', $outletIds);
        }

        $karyawans = $query->with('outlet')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $karyawans,
        ]);
    }

    /**
     * GET /users/karyawan/{karyawan}
     * Show detail karyawan
     */
    public function showKaryawan(Request $request, User $karyawan)
    {
        $user = auth()->user();

        // SECURITY: Karyawan harus role karyawan
        if ($karyawan->role !== 'karyawan') {
            return response()->json([
                'success' => false,
                'message' => 'User bukan karyawan',
            ], 422);
        }

        // SECURITY: Manager hanya lihat karyawan di outlet miliknya
        if ($user->isManager() && !$user->ownedOutlets()->where('id', $karyawan->outlet_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // Developer bisa lihat semua
        if (!$user->isDeveloper() && !$user->isManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $karyawan->load('outlet')->makeHidden(['password', 'remember_token']),
        ]);
    }

    /**
     * PUT /users/karyawan/{karyawan}
     * Update karyawan
     */
    public function updateKaryawan(Request $request, User $karyawan)
    {
        $user = auth()->user();

        // SECURITY: Karyawan harus role karyawan
        if ($karyawan->role !== 'karyawan') {
            return response()->json([
                'success' => false,
                'message' => 'User bukan karyawan',
            ], 422);
        }

        // SECURITY: Manager hanya update karyawan di outlet miliknya
        if ($user->isManager() && !$user->ownedOutlets()->where('id', $karyawan->outlet_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // Developer bisa update semua
        if (!$user->isDeveloper() && !$user->isManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // Validasi input
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($karyawan->id)],
            'password' => 'sometimes|required|min:8',
            'pin' => ['sometimes', 'required', 'digits:6'],
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = strtolower($validated['email']);
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        // SECURITY: PIN validation
        if (isset($validated['pin'])) {
            $existingPin = User::where('outlet_id', $karyawan->outlet_id)
                ->where('pin', $validated['pin'])
                ->where('id', '!=', $karyawan->id)
                ->exists();

            if ($existingPin) {
                return response()->json([
                    'success' => false,
                    'message' => 'PIN sudah digunakan di outlet ini',
                ], 422);
            }
        }

        $karyawan->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Karyawan berhasil diperbarui',
            'data' => $karyawan->makeHidden(['password', 'remember_token', 'pin']),
        ]);
    }

    /**
     * DELETE /users/karyawan/{karyawan}
     * Delete karyawan
     */
    public function deleteKaryawan(Request $request, User $karyawan)
    {
        $user = auth()->user();

        // SECURITY: Karyawan harus role karyawan
        if ($karyawan->role !== 'karyawan') {
            return response()->json([
                'success' => false,
                'message' => 'User bukan karyawan',
            ], 422);
        }

        // SECURITY: Manager hanya delete karyawan di outlet miliknya
        if ($user->isManager() && !$user->ownedOutlets()->where('id', $karyawan->outlet_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // Developer bisa delete semua
        if (!$user->isDeveloper() && !$user->isManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $karyawan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Karyawan berhasil dihapus',
        ]);
    }

    // ===== DEVELOPER USER MANAGEMENT (Developer only) =====

    /**
     * GET /users
     * Developer list semua users
     */
    public function listUsers(Request $request)
    {
        $user = auth()->user();

        if (!$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya developer',
            ], 403);
        }

        $users = User::with('outlet', 'ownedOutlets')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $users->makeHidden(['password', 'remember_token']),
        ]);
    }

    /**
     * POST /users
     * Developer create user apapun
     */
    public function createUser(Request $request)
    {
        $user = auth()->user();

        if (!$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya developer',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'pin' => 'nullable|digits:6',
            'role' => 'required|in:developer,manager,karyawan',
            'outlet_id' => 'nullable|exists:outlets,id',
            'is_active' => 'boolean',
        ]);

        $newUser = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'pin' => $validated['pin'] ?? null,
            'role' => $validated['role'],
            'outlet_id' => $validated['outlet_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dibuat',
            'data' => $newUser->load('outlet', 'ownedOutlets')->makeHidden(['password', 'remember_token']),
        ], 201);
    }

    /**
     * GET /users/{id}
     * Show detail user
     */
    public function showUser(Request $request, User $user)
    {
        $authUser = auth()->user();

        if (!$authUser->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya developer',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $user->load('outlet', 'ownedOutlets')->makeHidden(['password', 'remember_token']),
        ]);
    }

    /**
     * PUT /users/{id}
     * Update user
     */
    public function updateUser(Request $request, User $user)
    {
        $authUser = auth()->user();

        if (!$authUser->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya developer',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|required|min:8',
            'pin' => 'sometimes|nullable|digits:6',
            'role' => 'sometimes|required|in:developer,manager,karyawan',
            'outlet_id' => 'sometimes|nullable|exists:outlets,id',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = strtolower($validated['email']);
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'User berhasil diperbarui',
            'data' => $user->load('outlet', 'ownedOutlets')->makeHidden(['password', 'remember_token']),
        ]);
    }

    /**
     * DELETE /users/{id}
     * Delete user
     */
    public function deleteUser(Request $request, User $user)
    {
        $authUser = auth()->user();

        if (!$authUser->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya developer',
            ], 403);
        }

        // SECURITY: Cegah delete developer jika hanya ada 1
        if ($user->isDeveloper()) {
            $developerCount = User::where('role', 'developer')->count();
            if ($developerCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus developer terakhir',
                ], 422);
            }
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dihapus',
        ]);
    }
}

