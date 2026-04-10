<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Outlet;
use Illuminate\Support\Facades\DB;

class OutletController extends Controller
{
    /**
     * Create outlet (manager only)
     */
    public function createOutlet(Request $request)
    {
        $user = auth()->user();

        // role check
        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // validasi
        $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|string|max:255',
            'phone_number_outlet' => 'nullable|string|max:30',
            'address_outlet' => 'nullable|string|max:255',
        ]);

        // pakai transaction biar aman
        $outlet = DB::transaction(function () use ($request, $user) {

            $outlet = Outlet::create([
                'name' => $request->name,
                'image' => $request->image,
                'phone_number_outlet' => $request->phone_number_outlet,
                'address_outlet' => $request->address_outlet,
                'owner_id' => $user->id
            ]);

            // jadikan outlet pertama sebagai outlet default manager
            if (!$user->outlet_id) {
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
     * List outlet (milik user)
     */
    public function index()
    {
        $outlets = Outlet::where('owner_id', auth()->id())
            ->latest()
            ->get();

        return response()->json($outlets);
    }

    /**
     * Create outlet (manager only)
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        // role check
        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // validasi
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|string|max:255',
            'phone_number_outlet' => 'nullable|string|max:30',
            'address_outlet' => 'nullable|string|max:255',
        ]);

        $outlet = DB::transaction(function () use ($validated, $user) {

            $outlet = Outlet::create([
                'name' => $validated['name'],
                'image' => $validated['image'] ?? null,
                'phone_number_outlet' => $validated['phone_number_outlet'] ?? null,
                'address_outlet' => $validated['address_outlet'] ?? null,
                'owner_id' => $user->id
            ]);

            if (!$user->outlet_id) {
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
     * Show detail outlet
     */
    public function show(Outlet $outlet)
    {
        $this->authorizeOutlet($outlet);

        return response()->json($outlet);
    }

    /**
     * Update outlet
     */
    public function update(Request $request, Outlet $outlet)
    {
        $this->authorizeOutlet($outlet);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|string|max:255',
            'phone_number_outlet' => 'nullable|string|max:30',
            'address_outlet' => 'nullable|string|max:255',
        ]);

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
            $outlet->karyawans()->update([
                'outlet_id' => null
            ]);

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
}
