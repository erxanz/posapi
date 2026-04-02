<?php

namespace App\Http\Controllers;

use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StationController extends Controller
{
    /**
     * List stations
     */
    public function index(Request $request)
    {
        $query = Station::query()
            ->withCount('products') // lebih ringan dari with()
            ->latest();

        $query->where('outlet_id', auth()->user()->outlet_id);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
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

        if (empty($data['code'])) {
            $data['code'] = strtoupper(Str::slug($request->name));
        }

        $data['outlet_id'] = $user->outlet_id;

        $station = Station::create($data);

        return response()->json($station, 201);
    }

    /**
     * Show station
     */
    public function show(Station $station)
    {
        $this->authorizeStation($station);

        // eager load + count
        $station->loadCount('products');

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

        return response()->json($station, 200);
    }

    /**
     * Delete station
     */
    public function destroy(Station $station)
    {
        $this->authorizeStation($station);

        // tanpa load semua products (hemat)
        if ($station->products()->exists()) {
            return response()->json([
                'message' => 'Station masih digunakan oleh produk'
            ], 422);
        }

        $station->delete();

        return response()->json([
            'message' => 'Station deleted successfully'
        ], 200);
    }

    /**
     * Get products by station
     */
    public function products($id)
    {
        $station = Station::query()
            ->where('id', $id)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        // eager loading nested (ANTI N+1)
        $products = $station->products()
            ->with([
                'category:id,name' // select field saja
            ])
            ->where('is_active', true)
            ->get([
                'id',
                'name',
                'price',
                'category_id',
                'station_id'
            ]);

        return response()->json([
            'station' => $station,
            'products' => $products
        ]);
    }

    /**
     * Helper authorization
     */
    private function authorizeStation($station)
    {
        if ($station->outlet_id !== auth()->user()->outlet_id) {
            abort(403, 'Forbidden');
        }
    }
}
