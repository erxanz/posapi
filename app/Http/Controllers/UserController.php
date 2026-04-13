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

        if (!$user->isDeveloper() && !$user->isManager()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        // 1. MANAGER: cari semua outlet dimana ia menjadi owner_id, lalu ambil karyawannya
        if ($user->isManager()) {
            $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds)->where('role', 'karyawan');
        }
        // 2. DEVELOPER: bebas akses
        elseif ($user->isDeveloper()) {
            if ($request->filled('manager_id')) {
                $managerId = $request->manager_id;
                $outletIds = Outlet::where('owner_id', $managerId)->pluck('id');
                $query->whereIn('outlet_id', $outletIds)->where('role', 'karyawan');
            } elseif ($request->filled('role')) {
                $query->where('role', $request->role);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $limit = (int) $request->input('limit', 100);
        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($limit > 0 ? $limit : 100),
        ]);
    }

    public function listUsers(Request $request)
    {
        return $this->index($request);
    }

    /**
     * CREATE USER
     */
    public function createUser(Request $request)
    {
        $authUser = auth()->user();

        if (!$authUser->isDeveloper() && (!$authUser->isManager())) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $role = $request->role;
        $outlet_id = $request->outlet_id;

        // Validasi Khusus Manager
        if ($authUser->isManager()) {
            $role = 'karyawan';

            if (!$outlet_id) {
                return response()->json(['message' => 'Outlet penempatan wajib dipilih.'], 400);
            }

            // Pastikan outlet yang dipilih di form benar-benar dimiliki oleh Manager ini
            $ownsOutlet = Outlet::where('id', $outlet_id)->where('owner_id', $authUser->id)->exists();
            if (!$ownsOutlet) {
                return response()->json(['message' => 'Outlet tujuan tidak valid atau bukan milik Anda.'], 403);
            }
        }

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email',

            // PERBAIKAN: Password hanya wajib untuk Manager/Developer. Karyawan boleh kosong.
            'password' => $role === 'karyawan' ? 'nullable|min:6' : 'required|min:6',

            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
            'pin' => [
                'nullable',
                'digits:6',
                Rule::unique('users')->where(fn ($q) => $q->where('outlet_id', $outlet_id))
            ],
            'role' => $authUser->isDeveloper() ? 'required|in:developer,manager,karyawan' : 'nullable',
            'outlet_id' => $authUser->isDeveloper() ? 'nullable|exists:outlets,id' : 'nullable'
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

            // PERBAIKAN: Jika password kosong (karyawan), set default ke '12345678'
            'password' => Hash::make($request->password ?: '12345678'),

            'image' => $imagePath,
            'phone_number' => $request->phone_number,
            'pin' => $request->pin,
            'role' => $role,
            'outlet_id' => $outlet_id
        ]);

        return response()->json(['message' => 'User berhasil dibuat', 'data' => $user], 201);
    }

    /**
     * UPDATE USER
     */
    public function updateUser(Request $request, $id)
    {
        $authUser = auth()->user();

        if (!$authUser->isDeveloper() && !$authUser->isManager()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $user = User::findOrFail($id);

        // Validasi Khusus Manager saat mau mengedit
        if ($authUser->isManager()) {
            if ($user->role !== 'karyawan') {
                return response()->json(['message' => 'Akses ditolak. Anda hanya bisa mengedit karyawan.'], 403);
            }

            // Pastikan Karyawan ini aslinya memang dari outlet milik Manager
            $ownsCurrentOutlet = Outlet::where('id', $user->outlet_id)->where('owner_id', $authUser->id)->exists();
            if (!$ownsCurrentOutlet) {
                return response()->json(['message' => 'Akses ditolak. Karyawan ini tidak berada di bawah wewenang Anda.'], 403);
            }
        }

        // Tentukan outlet tujuan untuk validasi PIN unik per outlet
        $targetOutletId = $authUser->isDeveloper() ? $request->outlet_id : ($request->filled('outlet_id') ? $request->outlet_id : $user->outlet_id);

        $request->validate([
            'name' => 'required',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($id)],
            'password' => 'nullable|min:6',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number' => 'nullable|string|max:30',
            'pin' => [
                'nullable',
                'digits:6',
                Rule::unique('users')->where(fn ($q) => $q->where('outlet_id', $targetOutletId))->ignore($id)
            ],
            'role' => $authUser->isDeveloper() ? 'required|in:developer,manager,karyawan' : 'nullable',
            'outlet_id' => $authUser->isDeveloper() ? 'nullable|exists:outlets,id' : 'nullable'
        ]);

        // Simpan data umum
        $user->name = $request->name;
        $user->email = strtolower($request->email);
        $user->phone_number = $request->phone_number;

        // Logika Perpindahan Outlet & Perubahan Role
        if ($authUser->isDeveloper()) {
            $user->role = $request->role;
            $user->outlet_id = $request->outlet_id;
        }
        // Jika Manager merubah dropdown Outlet di form
        elseif ($authUser->isManager() && $request->filled('outlet_id')) {
            $newOutletId = $request->outlet_id;

            // Cek lagi: Apakah outlet BARU yang dipilih di dropdown juga milik Manager ini?
            $ownsNewOutlet = Outlet::where('id', $newOutletId)->where('owner_id', $authUser->id)->exists();
            if (!$ownsNewOutlet) {
                return response()->json(['message' => 'Penempatan gagal. Outlet tujuan bukan milik Anda.'], 403);
            }
            $user->outlet_id = $newOutletId;
        }

        if ($request->hasFile('image')) {
            $user->image = $request->file('image')->store('users', 'public');
        }

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        if ($request->has('pin')) {
            $user->pin = $request->pin;
        }

        if ($request->has('is_active')) {
            $user->is_active = $request->is_active;
        }

        $user->save();

        return response()->json(['message' => 'Data User berhasil diperbarui', 'data' => $user]);
    }

    /**
     * SHOW USER
     */
    public function showUser($id)
    {
        $authUser = auth()->user();

        if (!$authUser->isDeveloper() && !$authUser->isManager()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $user = User::findOrFail($id);

        if ($authUser->isManager()) {
            $ownsCurrentOutlet = Outlet::where('id', $user->outlet_id)->where('owner_id', $authUser->id)->exists();
            if ($user->role !== 'karyawan' || !$ownsCurrentOutlet) {
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

        if (!$authUser->isDeveloper() && !$authUser->isManager()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $user = User::findOrFail($id);

        if ($authUser->isManager()) {
            $ownsCurrentOutlet = Outlet::where('id', $user->outlet_id)->where('owner_id', $authUser->id)->exists();
            if ($user->role !== 'karyawan' || !$ownsCurrentOutlet) {
                return response()->json(['message' => 'Akses ditolak'], 403);
            }
        }

        $user->delete();

        return response()->json(['message' => 'User berhasil dihapus']);
    }
}
