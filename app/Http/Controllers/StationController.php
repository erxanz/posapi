<?php

namespace App\Http\Controllers;

use App\Models\Station;
use Illuminate\Http\Request;

class StationController extends Controller
{
    /**
     * List stations
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $limit = min($request->limit ?? 10, 100);

        $query = Station::query()
            ->select(['id', 'outlet_id', 'name', 'created_at'])
            ->where('outlet_id', $user->outlet_id)
            ->withCount([
                'products',
                'orders as orders_count' => function ($q) {
                    $q->distinct(); // hindari duplicate count
                }
            ])
            ->latest();

        if ($request->filled('search')) {
            $query->where('name', 'like', trim($request->search) . '%');
        }

        return response()->json([
            'data' => $query->paginate($limit)
        ]);
    }

    /**
     * Store station
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $station = Station::create([
            'name' => $request->name,
            'outlet_id' => $user->outlet_id,
        ]);

        return response()->json([
            'data' => $station
        ], 201);
    }

    /**
     * Show station
     */
    public function show(Station $station)
    {
        $this->authorizeStation($station);

        $station->loadCount([
            'products',
            'orders'
        ]);

        return response()->json([
            'data' => $station
        ]);
    }

    /**
     * Update station
     */
    public function update(Request $request, Station $station)
    {
        $this->authorizeStation($station);

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $station->update([
            'name' => $request->name
        ]);

        return response()->json([
            'data' => $station
        ]);
    }

    /**
     * Delete station
     */
    public function destroy(Station $station)
    {
        $this->authorizeStation($station);

        if ($station->products()->exists() || $station->orderItems()->exists()) {
            return response()->json([
                'message' => 'Station masih digunakan oleh produk atau item pesanan'
            ], 422);
        }

        $station->delete();

        return response()->json([
            'message' => 'Station deleted successfully'
        ]);
    }

    /**
     * Get products by station
     */
    public function products($id)
    {
        $user = auth()->user();

        $station = Station::query()
            ->select(['id', 'name', 'outlet_id'])
            ->where('id', $id)
            ->where('outlet_id', $user->outlet_id)
            ->with([
                'products' => function ($q) {
                    $q->select([
                        'id',
                        'name',
                        'price',
                        'category_id',
                        'station_id'
                    ])
                    ->where('is_active', true)
                    ->with([
                        'category:id,name'
                    ]);
                }
            ])
            ->firstOrFail();

        return response()->json([
            'data' => $station
        ]);
    }

    /**
     * Authorization helper
     */
    private function authorizeStation(Station $station): void
    {
        if ($station->outlet_id !== auth()->user()->outlet_id) {
            abort(403, 'Forbidden');
        }
    }
}
