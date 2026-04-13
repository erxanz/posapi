<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OutletController extends Controller
{
    /**
     * List outlet (Disesuaikan berdasarkan Role)
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Ambil data outlet beserta data owner-nya
        $query = Outlet::with('owner')->latest();

        // Jika manager, hanya tampilkan outlet miliknya
        if ($user->role === 'manager') {
            $query->where('owner_id', $user->id);
        }
        // Jika karyawan, tampilkan outlet tempat dia bekerja
        elseif ($user->role === 'karyawan') {
            $query->where('id', $user->outlet_id);
        }
        // Jika developer, $query tidak difilter (Tampil Semua)

        $limit = $request->input('limit', 100);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($limit)
        ]);
    }

    /**
     * Create outlet (Manager & Developer)
     */
    public function createOutlet(Request $request)
    {
        $user = auth()->user();

        // role check
        if ($user->role !== 'manager' && $user->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // validasi (Tambahan user_id untuk input dari Developer)
        $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'phone_number_outlet' => 'nullable|string|max:30',
            'address_outlet' => 'nullable|string|max:255',
            'user_id' => 'nullable|exists:users,id', // Diisi oleh Developer
        ]);

        $imagePath = null; // Set default null agar aman
        if ($request->hasFile('image')) {
            $imagePath = $this->storeImageIfUploaded($request);
        } elseif ($request->filled('image')) {
            $imagePath = $request->input('image');
        }

        // PERBAIKAN: Menambahkan $imagePath ke dalam 'use' closure function
        $outlet = DB::transaction(function () use ($request, $user, $imagePath) {

            // Menentukan siapa owner-nya
            $ownerId = $user->id; // Default untuk Manager

            // Jika Developer yang membuat, ambil ID manager dari form frontend
            if ($user->role === 'developer' && $request->filled('user_id')) {
                $ownerId = $request->user_id;
            }

            $outlet = Outlet::create([
                'name' => $request->name,
                'image' => $imagePath,
                'phone_number_outlet' => $request->phone_number_outlet,
                'address_outlet' => $request->address_outlet,
                'owner_id' => $ownerId
            ]);

            // jadikan outlet pertama sebagai outlet default manager (Hanya jika yg login adalah Manager)
            if ($user->role === 'manager' && !$user->outlet_id) {
                $user->update([
                    'outlet_id' => $outlet->id
                ]);
            }

            return $outlet;
        });

        return response()->json([
            'message' => 'Outlet berhasil dibuat',
            'data' => $outlet
        ], 201);
    }

    /**
     * Store outlet (Alternate endpoint, sama dengan createOutlet)
     */
    public function store(Request $request)
    {
        return $this->createOutlet($request);
    }

    /**
     * Show detail outlet
     */
    public function show(Outlet $outlet)
    {
        $this->authorizeOutlet($outlet);
        $outlet->load('owner');

        return response()->json($outlet);
    }

    /**
     * Update outlet
     */
    public function update(Request $request, Outlet $outlet)
    {
        $this->authorizeOutlet($outlet);
        $user = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable',
            'phone_number_outlet' => 'nullable|string|max:30',
            'address_outlet' => 'nullable|string|max:255',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($request->hasFile('image')) {
            if ($outlet->image) {
                Storage::disk('public')->delete($outlet->image);
            }

            $validated['image'] = $this->storeImageIfUploaded($request);
        }

        // Jika developer mengupdate owner outlet
        if ($user->role === 'developer' && $request->filled('user_id')) {
            $validated['owner_id'] = $request->user_id;
        }

        // Hapus kunci user_id dari array karena tidak ada kolom user_id di tabel outlets
        unset($validated['user_id']);

        $outlet->update($validated);

        return response()->json([
            'message' => 'Outlet berhasil diupdate',
            'data' => $outlet
        ]);
    }

    /**
     * Get products assigned to this outlet
     */
    public function getProducts(Outlet $outlet)
    {
        $this->authorizeOutlet($outlet);

        // Ambil relasi products beserta pivot datanya
        $products = $outlet->products()->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Sync products to this outlet (Checklist, Harga, Stok)
     */
    public function syncProducts(Request $request, Outlet $outlet)
    {
        $user = auth()->user();

        // BLOKIR AKSES DEVELOPER UNTUK MENGATUR MENU
        if ($user->role === 'developer') {
            return response()->json([
                'success' => false,
                'message' => 'Akses Ditolak: Developer tidak diizinkan mengatur katalog menu outlet.'
            ], 403);
        }

        $this->authorizeOutlet($outlet);

        $request->validate([
            'products' => 'nullable|array',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.price' => 'required|integer|min:0',
            'products.*.stock' => 'required|integer|min:0',
            'products.*.station_id' => 'nullable|exists:stations,id',
            'products.*.is_active' => 'required|boolean',
        ]);

        $syncData = [];

        if ($request->has('products')) {
            foreach ($request->products as $item) {
                // Mapping data untuk masuk ke tabel pivot 'outlet_product'
                $syncData[$item['product_id']] = [
                    'price' => $item['price'],
                    'stock' => $item['stock'],
                    'station_id' => $item['station_id'] ?? null,
                    'is_active' => $item['is_active'],
                ];
            }
        }

        // Gunakan sync() bawaan Laravel. Ini otomatis menghapus menu yang tidak dicentang
        // dan menambahkan/mengupdate menu yang dicentang.
        $outlet->products()->sync($syncData);

        return response()->json([
            'success' => true,
            'message' => 'Katalog outlet berhasil diperbarui.'
        ]);
    }

    /**
     * Delete outlet
     */
    public function destroy(Outlet $outlet)
    {
        $this->authorizeOutlet($outlet);

        DB::transaction(function () use ($outlet) {

            // kosongkan outlet_id semua karyawan
            // Note: Pastikan relasi 'karyawans' sudah ada di model Outlet
            if (method_exists($outlet, 'karyawans')) {
                 $outlet->karyawans()->update([
                    'outlet_id' => null
                ]);
            }

            $outlet->delete();
        });

        return response()->json([
            'message' => 'Outlet berhasil dihapus'
        ]);
    }

    /**
     * Authorization helper
     */
    private function authorizeOutlet(Outlet $outlet): void
    {
        $user = auth()->user();

        $isOwner = (int) $outlet->owner_id === (int) $user->id;
        $isAssignedManager = $user->role === 'manager' && (int) $user->outlet_id === (int) $outlet->id;
        $isDeveloper = $user->role === 'developer';

        if (!$isOwner && !$isAssignedManager && !$isDeveloper) {
            abort(403, 'Forbidden');
        }
    }

    private function storeImageIfUploaded(Request $request): string
    {
        return $request->file('image')->store('outlets', 'public');
    }
}