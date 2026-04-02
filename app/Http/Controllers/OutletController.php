<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Outlet;
use Illuminate\Support\Facades\DB;

// class OutletController extends Controller
// {
//     /**
//      * Create outlet (manager only)
//      */
//     public function createOutlet(Request $request)
//     {
//         $user = auth()->user();

//         // role check
//         if ($user->role !== 'manager') {
//             return response()->json(['message' => 'Forbidden'], 403);
//         }

//         // validasi
//         $request->validate([
//             'name' => 'required|string|max:255'
//         ]);

//         // optional: cegah manager punya banyak outlet
//         if ($user->outlet_id) {
//             return response()->json([
//                 'message' => 'Manager sudah memiliki outlet'
//             ], 422);
//         }

//         // pakai transaction biar aman
//         $outlet = DB::transaction(function () use ($request, $user) {

//             $outlet = Outlet::create([
//                 'name' => $request->name,
//                 'owner_id' => $user->id
//             ]);

//             // assign outlet ke user
//             $user->update([
//                 'outlet_id' => $outlet->id
//             ]);

//             return $outlet;
//         });

//         return response()->json([
//             'message' => 'Outlet berhasil dibuat',
//             'data' => $outlet
//         ], 201);
//     }
// }

class OutletController extends Controller
{
    /**
     * List outlet (hanya milik user)
     */
    public function index()
    {
        $user = auth()->user();

        $outlets = Outlet::where('owner_id', $user->id)
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

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        // optional: 1 manager = 1 outlet
        if ($user->outlet_id) {
            return response()->json([
                'message' => 'Manager sudah memiliki outlet'
            ], 422);
        }

        $outlet = DB::transaction(function () use ($request, $user) {

            $outlet = Outlet::create([
                'name' => $request->name,
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

        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        $outlet->update([
            'name' => $request->name
        ]);

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

            // optional: kosongkan outlet_id user
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
     * Helper authorization (owner only)
     */
    private function authorizeOutlet(Outlet $outlet)
    {
        if ($outlet->owner_id !== auth()->id()) {
            abort(403, 'Forbidden');
        }
    }
}
