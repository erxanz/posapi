<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Outlet;

class UserController extends Controller
{
    /**
     * LIST USER (ROLE-BASED)
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = User::with('outlet');

        // Hanya role developer/manager yang boleh mengakses daftar user.
        if (!$user->isDeveloper() && !$user->isManager()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        // 1. Jika login sebagai MANAGER, hanya tampilkan karyawan di outlet miliknya.
        if ($user->isManager()) {
            $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');

            $query->whereIn('outlet_id', $outletIds)
                ->where('role', 'karyawan');
        }
        // 2. Jika login sebagai DEVELOPER.
        elseif ($user->isDeveloper()) {
            // Jika developer meminta karyawan dari manager tertentu.
            if ($request->filled('manager_id')) {
                $managerId = $request->manager_id;
                $outletIds = Outlet::where('owner_id', $managerId)->pluck('id');

                $query->whereIn('outlet_id', $outletIds)
                    ->where('role', 'karyawan');
            }
            // Filter role biasa (untuk tabel utama).
            elseif ($request->filled('role')) {
                $query->where('role', $request->role);
            }
        }

        // Search fitur.
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $limit = (int) $request->input('limit', 100);
        if ($limit <= 0) {
            $limit = 100;
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($limit),
        ]);
    }

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
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
            'pin' => [
                'required',
                'digits:6',
                Rule::unique('users')->where(function ($q) use ($user) {
                    return $q->where('outlet_id', $user->outlet_id);
                })
            ]
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('users', 'public');
        } elseif ($request->filled('image')) {
            $imagePath = $request->image;
        }

        $karyawan = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
            'image' => $imagePath,
            'phone_number' => $request->phone_number,

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
            ->select('id', 'name', 'email', 'image', 'role', 'is_active', 'pin')
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
            ->select('id', 'name', 'email', 'image', 'role', 'is_active', 'pin')
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
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
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
        $karyawan->phone_number = $request->phone_number;

        if ($request->hasFile('image')) {
            $karyawan->image = $request->file('image')->store('users', 'public');
        } elseif ($request->filled('image')) {
            $karyawan->image = $request->image;
        }

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

    public function listUsers(Request $request)
    {
        return $this->index($request);
    }

    public function createUser(Request $request)
    {
        $this->authorizeDeveloper();

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
            'pin' => 'nullable|digits:6',
            'role' => 'required|in:developer,manager,karyawan',
            'outlet_id' => 'nullable|exists:outlets,id'
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('users', 'public');
        } elseif ($request->filled('image')) {
            $imagePath = $request->image;
        }

        $user = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
            'image' => $imagePath,
            'phone_number' => $request->phone_number,
            'pin' => $request->pin, // TANPA HASH
            'role' => $request->role,
            'outlet_id' => $request->outlet_id
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat',
            'data' => $user
        ], 201);
    }

    /**
     * UPDATE USER (DEVELOPER)
     */
    public function updateUser(Request $request, $id)
    {
        $this->authorizeDeveloper();

        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'required',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($id)
            ],
            'password' => 'nullable|min:6',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
            'pin' => 'nullable|digits:6',
            'role' => 'required|in:developer,manager,karyawan',
            'outlet_id' => 'nullable|exists:outlets,id'
        ]);

        $user->name = $request->name;
        $user->email = strtolower($request->email);
        $user->phone_number = $request->phone_number;
        $user->role = $request->role;
        $user->outlet_id = $request->outlet_id;

        if ($request->hasFile('image')) {
            $user->image = $request->file('image')->store('users', 'public');
        } elseif ($request->filled('image')) {
            $user->image = $request->image;
        }

        if ($request->password) {
            $user->password = Hash::make($request->password);
        }

        if ($request->pin) {
            $user->pin = $request->pin;
        }

        $user->save();

        return response()->json([
            'message' => 'User berhasil diperbarui',
            'data' => $user
        ]);
    }

    public function showUser($id)
    {
        $this->authorizeDeveloper();
        $user = User::findOrFail($id);
        return response()->json(['data' => $user]);
    }

    public function deleteUser($id)
    {
        $this->authorizeDeveloper();
        User::findOrFail($id)->delete();
        return response()->json(['message' => 'User berhasil dihapus']);
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
