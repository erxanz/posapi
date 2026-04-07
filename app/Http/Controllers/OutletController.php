<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Outlet;
use Illuminate\Support\Facades\DB;

class OutletController extends Controller
{
    /**
     * GET /outlets
     * Manager dan Developer dapat melihat outlets mereka
     *
     * Manager: hanya outlets yang dia own
     * Developer: semua outlets
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Outlet::query();

        // Filter berdasarkan role
        if ($user->isManager()) {
            // Manager hanya lihat outlets miliknya
            $query->where('owner_id', $user->id);
        }
        // Developer bisa lihat semua (no filter)

        $outlets = $query->with('owner', 'karyawans')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $outlets,
        ]);
    }

    /**
     * POST /outlets
     * Hanya Manager dan Developer yang dapat create outlet
     * Manager: outlet akan di-assign ke dirinya sendiri
     * Developer: bisa assign ke manager manapun
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        // SECURITY: Hanya manager dan developer yang bisa create outlet
        if (!$user->isManager() && !$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya manager dan developer yang dapat membuat outlet',
            ], 403);
        }

        // Validasi input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'owner_id' => $user->isDeveloper() ? 'required|exists:users,id' : 'nullable',
        ]);

        // SECURITY: Manager hanya bisa create outlet untuk dirinya
        if ($user->isManager()) {
            $validated['owner_id'] = $user->id;
        }

        $outlet = Outlet::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Outlet berhasil dibuat',
            'data' => $outlet->load('owner'),
        ], 201);
    }

    /**
     * POST /outlets (alias untuk store)
     * Backward compatibility
     */
    public function createOutlet(Request $request)
    {
        return $this->store($request);
    }

    /**
     * GET /outlets/{outlet}
     * Hanya bisa lihat detail outlet jika user adalah owner (manager) atau developer
     */
    public function show(Request $request, Outlet $outlet)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$outlet->isOwnedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Anda tidak dapat mengakses outlet ini',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $outlet->load('owner', 'karyawans', 'tables', 'categories', 'products', 'stations', 'orders'),
        ]);
    }

    /**
     * PUT /outlets/{outlet}
     * Manager hanya bisa update outlet miliknya
     * Developer bisa update outlet apapun
     */
    public function update(Request $request, Outlet $outlet)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$outlet->isOwnedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Anda tidak dapat mengubah outlet ini',
            ], 403);
        }

        // Validasi input
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
        ]);

        $outlet->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Outlet berhasil diperbarui',
            'data' => $outlet,
        ]);
    }

    /**
     * DELETE /outlets/{outlet}
     * Hanya owner atau developer yang bisa delete
     */
    public function destroy(Request $request, Outlet $outlet)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$outlet->isOwnedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Anda tidak dapat menghapus outlet ini',
            ], 403);
        }

        // SECURITY: Cek apakah outlet masih punya data
        $karyawansCount = $outlet->karyawans()->count();
        if ($karyawansCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Tidak dapat menghapus outlet karena masih ada {$karyawansCount} karyawan",
            ], 422);
        }

        $ordersCount = $outlet->orders()->count();
        if ($ordersCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Tidak dapat menghapus outlet karena masih ada {$ordersCount} order",
            ], 422);
        }

        $outlet->delete();

        return response()->json([
            'success' => true,
            'message' => 'Outlet berhasil dihapus',
        ]);
    }
}

