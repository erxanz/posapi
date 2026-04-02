<?php

namespace App\Http\Controllers;

use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StationController extends Controller
{
    /**
     * List stations (OPTIMIZED)
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Station::query()
            ->select(['id', 'outlet_id', 'name', 'code', 'is_active', 'created_at'])
            ->where('outlet_id', $user->outlet_id)
            ->withCount([
                'products as products_count' => function ($q) {
                    $q->where('is_active', true);
                },
                'orders'
            ])
            ->latest();

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . trim($request->search) . '%');
        }

        return response()->json(
            $query->paginate($request->limit ?? 10)
        );
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
            'code' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $data = $request->only(['name', 'code', 'is_active']);

        // auto code
        if (empty($data['code'])) {
            $data['code'] = strtoupper(Str::slug($request->name));
        }

        $data['outlet_id'] = $user->outlet_id;

        $station = Station::create($data);

        return response()->json($station, 201);
    }

    /**
     * Show station (NO N+1)
     */
    public function show(Station $station)
    {
        $this->authorizeStation($station);

        $station->loadCount([
            'products as products_count' => function ($q) {
                $q->where('is_active', true);
            },
            'orders'
        ]);

        return response()->json($station);
    }

    /**
     * Update station
     */
    public function update(Request $request, Station $station)
    {
        $this->authorizeStation($station);

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $data = $request->only(['name', 'code', 'is_active']);

        if (empty($data['code'])) {
            $data['code'] = strtoupper(Str::slug($request->name));
        }

        $station->update($data);

        return response()->json($station);
    }

    /**
     * Delete station
     */
    public function destroy(Station $station)
    {
        $this->authorizeStation($station);

        // SUPER EFISIEN (tidak load data)
        if ($station->products()->exists()) {
            return response()->json([
                'message' => 'Station masih digunakan oleh produk'
            ], 422);
        }

        $station->delete();

        return response()->json([
            'message' => 'Station deleted successfully'
        ]);
    }

    /**
     * Get products by station (ANTI N+1 TOTAL)
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
                        'category:id,name' // no N+1 category
                    ]);
                }
            ])
            ->firstOrFail();

        return response()->json($station);
    }

    /**
     * Helper authorization (REUSABLE)
     */
    private function authorizeStation(Station $station): void
    {
        if ($station->outlet_id !== auth()->user()->outlet_id) {
            abort(403, 'Forbidden');
        }
    }
}
