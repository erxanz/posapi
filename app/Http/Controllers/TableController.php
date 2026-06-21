<?php

namespace App\Http\Controllers;

use App\Models\Table;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TableController extends Controller
{
    /**
     * Pastikan user yang login berhak mengakses meja di outlet ini.
     * Tanpa ini, siapa pun yang authenticated bisa membuat/mengubah/menghapus
     * meja milik outlet siapa pun hanya dengan menebak outlet_id/table id.
     */
    private function authorizeOutletId(?int $outletId): void
    {
        $user = auth()->user();

        if ($user->role === 'developer') {
            return;
        }

        if ($user->role === 'karyawan') {
            if ((int) $user->outlet_id !== (int) $outletId) {
                abort(403, 'Forbidden');
            }
            return;
        }

        if ($user->role === 'manager') {
            $ownsOutlet = Outlet::where('id', $outletId)->where('owner_id', $user->id)->exists();
            if (!$ownsOutlet) {
                abort(403, 'Forbidden');
            }
            return;
        }

        abort(403, 'Forbidden');
    }

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
                  // FIX: Ubah 'token' jadi 'qr_token'
                  ->orWhere('qr_token', 'like', "%{$search}%");
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

        $this->authorizeOutletId($request->outlet_id);

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

        // FIX: Ubah 'token' jadi 'qr_token'
        while (Table::where('qr_token', $token)->exists()) {
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
            // FIX: Ubah 'token' jadi 'qr_token'
            'qr_token'  => $token,
        ]);

        return response()->json([
            'message' => 'Meja berhasil dibuat.',
            'data'    => $table,
            // FIX: Panggil property $table->qr_token
            'qr_url'  => url('/menu/' . $table->qr_token)
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
            'status'    => 'required|in:available,occupied,dirty,reserved',

            'is_active' => 'nullable|boolean',
        ]);

        // Cek otorisasi untuk outlet meja saat ini DAN outlet tujuan (kalau
        // beda) - mencegah meja "dipindah" ke/dari outlet yang bukan miliknya.
        $this->authorizeOutletId($table->outlet_id);
        $this->authorizeOutletId($request->outlet_id);

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
            // FIX: Panggil property $table->qr_token
            'qr_url'  => url('/menu/' . $table->qr_token)
        ]);
    }

    /**
     * Hapus meja
     */
    public function destroy(Table $table)
    {
        $this->authorizeOutletId($table->outlet_id);

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
        $this->authorizeOutletId($table->outlet_id);

        $token = (string) Str::uuid();

        // FIX: Ubah 'token' jadi 'qr_token'
        while (Table::where('qr_token', $token)->exists()) {
            $token = (string) Str::uuid();
        }

        $table->update([
            // FIX: Ubah 'token' jadi 'qr_token'
            'qr_token' => $token
        ]);

        return response()->json([
            'message' => 'Token QR berhasil diperbarui.',
            'data'    => $table,
            // FIX: Panggil property $table->qr_token
            'qr_url'  => url('/menu/' . $table->qr_token)
        ]);
    }
}
