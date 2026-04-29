<?php

namespace App\Http\Controllers;

use App\Models\Table;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TableController extends Controller
{
    /**
     * List meja
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Table::with('outlet');

        /*
        |--------------------------------------------------------------------------
        | FILTER BERDASARKAN ROLE
        |--------------------------------------------------------------------------
        */

        if ($user->role === 'karyawan') {
            // Karyawan hanya lihat cabangnya sendiri
            $query->where('outlet_id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            // Manager lihat semua outlet miliknya
            $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds);
        }

        /*
        |--------------------------------------------------------------------------
        | FILTER OUTLET
        |--------------------------------------------------------------------------
        */

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('token', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->latest()->paginate($request->limit ?? 15)
        );
    }

    /**
     * Simpan meja baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'name'      => 'required|string|max:255',
            'code'      => 'nullable|string|max:50',
            'capacity'  => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        /*
        |--------------------------------------------------------------------------
        | CEK NAMA DUPLIKAT DI OUTLET YANG SAMA
        |--------------------------------------------------------------------------
        */

        $exists = Table::where('outlet_id', $request->outlet_id)
            ->where('name', $request->name)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Nama meja sudah digunakan di outlet ini.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | GENERATE TOKEN QR
        |--------------------------------------------------------------------------
        */

        $token = (string) Str::uuid();

        while (Table::where('token', $token)->exists()) {
            $token = (string) Str::uuid();
        }

        /*
        |--------------------------------------------------------------------------
        | CREATE DATA
        |--------------------------------------------------------------------------
        */

        $table = Table::create([
            'outlet_id' => $request->outlet_id,
            'name'      => $request->name,
            'code'      => $request->code,
            'capacity'  => $request->capacity ?? 1,
            'status'    => 'available',
            'is_active' => $request->is_active ?? true,
            'token'     => $token,
        ]);

        return response()->json([
            'message' => 'Meja berhasil dibuat.',
            'data'    => $table,
            'qr_url'  => url('/menu/' . $table->token)
        ], 201);
    }

    /**
     * Update meja
     */
    public function update(Request $request, Table $table)
    {
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'name'      => 'required|string|max:255',
            'code'      => 'nullable|string|max:50',
            'capacity'  => 'nullable|integer|min:1',
            'status'    => 'required|in:available,occupied,dirty',
            'is_active' => 'nullable|boolean',
        ]);

        /*
        |--------------------------------------------------------------------------
        | CEK NAMA DUPLIKAT
        |--------------------------------------------------------------------------
        */

        $exists = Table::where('outlet_id', $request->outlet_id)
            ->where('name', $request->name)
            ->where('id', '!=', $table->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Nama meja sudah dipakai. Gunakan nama lain.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        */

        $table->update([
            'outlet_id' => $request->outlet_id,
            'name'      => $request->name,
            'code'      => $request->code,
            'capacity'  => $request->capacity,
            'status'    => $request->status,
            'is_active' => $request->is_active ?? $table->is_active,
        ]);

        return response()->json([
            'message' => 'Meja berhasil diperbarui.',
            'data'    => $table,
            'qr_url'  => url('/menu/' . $table->token)
        ]);
    }

    /**
     * Hapus meja
     */
    public function destroy(Table $table)
    {
        $table->delete();

        return response()->json([
            'message' => 'Meja berhasil dihapus.'
        ]);
    }

    /**
     * Regenerate token QR meja
     */
    public function regenerateToken(Table $table)
    {
        $token = (string) Str::uuid();

        while (Table::where('token', $token)->exists()) {
            $token = (string) Str::uuid();
        }

        $table->update([
            'token' => $token
        ]);

        return response()->json([
            'message' => 'Token QR berhasil diperbarui.',
            'data'    => $table,
            'qr_url'  => url('/menu/' . $table->token)
        ]);
    }
}
