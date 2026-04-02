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
}
