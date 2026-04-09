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
        $ownerId = $this->resolveOwnerId($user);

        if (!$ownerId) {
            return response()->json(['message' => 'Owner tidak ditemukan'], 400);
        }

        $limit = min($request->limit ?? 10, 100);

        $query = Station::query()
            ->select(['id', 'owner_id', 'name', 'created_at'])
            ->where('owner_id', $ownerId)
            ->withCount([
                'products',
                'orders as orders_count' => function ($q) {
                    $q->distinct();
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
        $ownerId = $this->resolveOwnerId($user);

        if (!$ownerId) {
            return response()->json(['message' => 'Owner tidak ditemukan'], 400);
        }

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $station = Station::create([
            'name' => $request->name,
            'owner_id' => $ownerId,
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
            ->select(['id', 'name', 'owner_id'])
            ->where('id', $id)
            ->firstOrFail();

        $this->authorizeStation($station);

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        $products = \App\Models\Outlet::query()
            ->findOrFail($user->outlet_id)
            ->products()
            ->select([
                'products.id',
                'products.name',
                'products.category_id',
                'products.station_id',
            ])
            ->where('products.station_id', $station->id)
            ->wherePivot('is_active', true)
            ->with(['category:id,name'])
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category_id' => $product->category_id,
                    'station_id' => $product->station_id,
                    'price' => (int) $product->pivot->price,
                    'stock' => (int) $product->pivot->stock,
                    'is_active' => (bool) $product->pivot->is_active,
                    'category' => $product->category,
                ];
            });

        return response()->json([
            'data' => [
                'id' => $station->id,
                'name' => $station->name,
                'owner_id' => $station->owner_id,
                'products' => $products,
            ]
        ]);
    }

    /**
     * Authorization helper
     */
    private function authorizeStation(Station $station): void
    {
        $ownerId = $this->resolveOwnerId(auth()->user());

        if (!$ownerId || (int) $station->owner_id !== (int) $ownerId) {
            abort(403, 'Forbidden');
        }
    }

    private function resolveOwnerId($user): ?int
    {
        if ($user->role === 'developer') {
            return request()->integer('owner_id') ?: $user->id;
        }

        if ($user->role === 'manager') {
            return $user->id;
        }

        if ($user->outlet_id) {
            return \App\Models\Outlet::query()->whereKey($user->outlet_id)->value('owner_id');
        }

        return null;
    }
}
