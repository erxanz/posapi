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

        // Hanya role developer & manager yang boleh mengakses daftar user.
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

    public function listUsers(Request $request)
    {
        return $this->index($request);
    }

    /**
     * CREATE USER (GABUNGAN DEVELOPER & MANAGER)
     */
    public function createUser(Request $request)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'developer' && $authUser->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $role = $request->role;
        $outlet_id = $request->outlet_id;

        // Jika yang membuat adalah Manager, paksa role dan outlet-nya
        if ($authUser->role === 'manager') {
            if (!$authUser->outlet_id) {
                return response()->json(['message' => 'Anda belum memiliki outlet untuk menempatkan karyawan'], 400);
            }
            $role = 'karyawan';
            $outlet_id = $authUser->outlet_id;
        }

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
            'pin' => [
                'nullable',
                'digits:6',
                Rule::unique('users')->where(function ($q) use ($outlet_id) {
                    return $q->where('outlet_id', $outlet_id);
                })
            ],
            'role' => $authUser->role === 'developer' ? 'required|in:developer,manager,karyawan' : 'nullable',
            'outlet_id' => $authUser->role === 'developer' ? 'nullable|exists:outlets,id' : 'nullable'
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
            'pin' => $request->pin,
            'role' => $role,
            'outlet_id' => $outlet_id
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat',
            'data' => $user
        ], 201);
    }

    /**
     * UPDATE USER (GABUNGAN DEVELOPER & MANAGER)
     */
    public function updateUser(Request $request, $id)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'developer' && $authUser->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $user = User::findOrFail($id);

        // Jika Manager, pastikan dia hanya mengedit karyawannya sendiri
        if ($authUser->role === 'manager') {
            if ($user->role !== 'karyawan' || $user->outlet_id !== $authUser->outlet_id) {
                return response()->json(['message' => 'Akses ditolak. Anda hanya bisa mengedit karyawan di outlet Anda.'], 403);
            }
        }

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
            'pin' => [
                'nullable',
                'digits:6',
                Rule::unique('users')->where(function ($q) use ($user) {
                    return $q->where('outlet_id', $user->outlet_id);
                })->ignore($id)
            ],
            'role' => $authUser->role === 'developer' ? 'required|in:developer,manager,karyawan' : 'nullable',
            'outlet_id' => $authUser->role === 'developer' ? 'nullable|exists:outlets,id' : 'nullable'
        ]);

        $user->name = $request->name;
        $user->email = strtolower($request->email);
        $user->phone_number = $request->phone_number;

        // Hanya Developer yang boleh merubah role dan menukar penempatan outlet
        if ($authUser->role === 'developer') {
            $user->role = $request->role;
            $user->outlet_id = $request->outlet_id;
        }

        if ($request->hasFile('image')) {
            $user->image = $request->file('image')->store('users', 'public');
        } elseif ($request->filled('image')) {
            $user->image = $request->image;
        }

        if ($request->password) {
            $user->password = Hash::make($request->password);
        }

        if ($request->has('pin')) {
            $user->pin = $request->pin;
        }

        if ($request->has('is_active')) {
            $user->is_active = $request->is_active;
        }

        $user->save();

        return response()->json([
            'message' => 'User berhasil diperbarui',
            'data' => $user
        ]);
    }

    /**
     * SHOW USER DETAIL
     */
    public function showUser($id)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'developer' && $authUser->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $user = User::findOrFail($id);

        if ($authUser->role === 'manager') {
            if ($user->role !== 'karyawan' || $user->outlet_id !== $authUser->outlet_id) {
                return response()->json(['message' => 'Akses ditolak'], 403);
            }
        }

        return response()->json(['data' => $user]);
    }

    /**
     * DELETE USER
     */
    public function deleteUser($id)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'developer' && $authUser->role !== 'manager') {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $user = User::findOrFail($id);

        if ($authUser->role === 'manager') {
            if ($user->role !== 'karyawan' || $user->outlet_id !== $authUser->outlet_id) {
                return response()->json(['message' => 'Akses ditolak'], 403);
            }
        }

        $user->delete();

        return response()->json([
            'message' => 'User berhasil dihapus'
        ]);
    }
}
