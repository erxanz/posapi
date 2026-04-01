<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Outlet;

class OutletController extends Controller
{
    public function createOutlet(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $outlet = Outlet::create([
            'name' => $request->name,
            'owner_id' => $user->id
        ]);

        // assign ke user
        $user->update([
            'outlet_id' => $outlet->id
        ]);

        return response()->json($outlet);
    }
}
