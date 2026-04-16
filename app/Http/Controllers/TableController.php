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

        // CEK: Apakah nama meja sudah ada di outlet ini?
        $existingTable = Table::where('outlet_id', $request->outlet_id)
            ->where('name', $request->name)
            ->first();

        if ($existingTable) {
            return response()->json(['message' => 'Nama meja sudah digunakan di outlet ini.'], 422);
        }

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

        // CEK BENTROK NAMA
        $existingTable = Table::where('outlet_id', $request->outlet_id)
            ->where('name', $request->name)
            ->where('id', '!=', $table->id)
            ->first();

        if ($existingTable) {
            return response()->json(['message' => 'Nama meja tersebut sudah dipakai. Silakan gunakan nama lain.'], 422);
        }

        $table->update($request->only(['outlet_id', 'name', 'code', 'capacity', 'status', 'is_active']));

        return response()->json(['message' => 'Meja berhasil diperbarui.', 'data' => $table]);
    }

    public function destroy(Table $table)
    {
        $table->delete();
        return response()->json(['message' => 'Meja berhasil dihapus.']);
    }
}
