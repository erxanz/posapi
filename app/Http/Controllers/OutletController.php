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
            'name' => 'required|string|max:255'
        ]);

        // optional: cegah manager punya banyak outlet
        if ($user->outlet_id) {
            return response()->json([
                'message' => 'Manager sudah memiliki outlet'
            ], 422);
        }

        // pakai transaction biar aman
        $outlet = DB::transaction(function () use ($request, $user) {

            $outlet = Outlet::create([
                'name' => $request->name,
                'owner_id' => $user->id
            ]);

            // assign outlet ke user
            $user->update([
                'outlet_id' => $outlet->id
            ]);

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
            'name' => 'required|string|max:255'
        ]);

        // 1 manager = 1 outlet
        if ($user->outlet_id) {
            return response()->json([
                'message' => 'Manager sudah memiliki outlet'
            ], 422);
        }

        $outlet = DB::transaction(function () use ($validated, $user) {

            $outlet = Outlet::create([
                'name' => $validated['name'],
                'owner_id' => $user->id
            ]);

            $user->update([
                'outlet_id' => $outlet->id
            ]);

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
            'name' => 'required|string|max:255'
        ]);

        $outlet->update($validated);

        return response()->json([
            'message' => 'Outlet berhasil diupdate',
            'data' => $outlet
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
