<?php

namespace App\Http\Controllers;

use App\Models\Table;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Table::with('outlet');

        // 1. FILTER BERDASARKAN ROLE
        if ($user->role === 'karyawan') {
            // Karyawan hanya melihat meja di cabangnya sendiri
            $query->where('outlet_id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            // Manager melihat meja dari SEMUA cabang yang dia miliki
            $outletIds = \App\Models\Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds);
        }
        // Jika Developer, tidak ada batasan (bisa lihat semua)

        // 2. FILTER DARI DROPDOWN VUE (Pilih Outlet Tertentu)
        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        // 3. FITUR PENCARIAN
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate($request->limit ?? 15));
    }

    public function store(Request $request)
    {
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'name'      => 'required|string|max:255',
            'code'      => 'nullable|string|max:50',
            'capacity'  => 'nullable|integer|min:1',
            'is_active' => 'boolean'
        ]);

        // CEK DATABASE: Apakah nama meja ini sudah ada (termasuk yang di tong sampah / soft delete)?
        $existingTable = Table::withTrashed()
            ->where('outlet_id', $request->outlet_id)
            ->where('name', $request->name)
            ->first();

        if ($existingTable) {
            if ($existingTable->trashed()) {
                // JIKA ADA DI TONG SAMPAH: Pulihkan (restore) dan update dengan data yang baru di-input
                $existingTable->restore();
                $existingTable->update([
                    'code'      => $request->code,
                    'capacity'  => $request->capacity ?? 1,
                    'is_active' => $request->is_active ?? true,
                    'status'    => 'available', // Reset status agar siap dipakai lagi
                ]);

                return response()->json(['message' => 'Meja berhasil dipulihkan dari riwayat hapus.', 'data' => $existingTable], 201);
            } else {
                // JIKA MEJA MASIH AKTIF: Berikan error validasi yang ramah pengguna
                return response()->json(['message' => 'Nama meja sudah digunakan di outlet ini.'], 422);
            }
        }

        // JIKA BENAR-BENAR BARU: Buat data baru
        $table = Table::create($request->only(['outlet_id', 'name', 'code', 'capacity', 'is_active']));

        return response()->json(['message' => 'Meja berhasil dibuat.', 'data' => $table], 201);
    }

    public function update(Request $request, Table $table)
    {
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'name'      => 'required|string|max:255',
            'code'      => 'nullable|string|max:50',
            'capacity'  => 'nullable|integer|min:1',
            'status'    => 'required|in:available,occupied,dirty',
            'is_active' => 'boolean'
        ]);

        // CEK BENTROK NAMA: Mencegah error 1062 jika user mengganti nama meja ke nama meja yang ada di tong sampah
        $existingTable = Table::withTrashed()
            ->where('outlet_id', $request->outlet_id)
            ->where('name', $request->name)
            ->where('id', '!=', $table->id)
            ->first();

        if ($existingTable) {
            return response()->json(['message' => 'Nama meja tersebut sudah dipakai (termasuk di riwayat hapus). Silakan gunakan nama lain.'], 422);
        }

        $table->update($request->only(['outlet_id', 'name', 'code', 'capacity', 'status', 'is_active']));

        return response()->json(['message' => 'Meja berhasil diperbarui.', 'data' => $table]);
    }

    public function destroy(Table $table)
    {
        $table->delete(); // Soft delete
        return response()->json(['message' => 'Meja berhasil dihapus.']);
    }
}
