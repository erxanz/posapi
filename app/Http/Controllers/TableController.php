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
        $data = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:1',
            'is_active' => 'boolean'
        ]);

        // Status dan QR Token akan di-generate otomatis oleh Model Events Anda!
        $table = Table::create($data);

        return response()->json(['message' => 'Meja berhasil dibuat', 'data' => $table], 201);
    }

    public function update(Request $request, Table $table)
    {
        $data = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:1',
            'status' => 'required|in:available,occupied,dirty',
            'is_active' => 'boolean'
        ]);

        $table->update($data);

        return response()->json(['message' => 'Meja berhasil diperbarui', 'data' => $table]);
    }

    public function destroy(Table $table)
    {
        $table->delete(); // Ini akan melakukan Soft Delete karena model Anda pakai SoftDeletes
        return response()->json(['message' => 'Meja berhasil dihapus']);
    }
}
