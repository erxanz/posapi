<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * GET /public/menu/{outletId}/{tableId}
     * Public API - Menu untuk Customer (QR code)
     *
     * SECURITY:
     * - Validasi table milik outlet
     * - Hanya tampilkan produk active dengan stock > 0
     * - Customer tidak perlu login
     */
    public function publicMenu($outletId, $tableId)
    {
        // SECURITY: Validasi table exists dan belongs to outlet
        $table = Table::query()
            ->where('id', $tableId)
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->firstOrFail();

        // Cache untuk performa
        $products = Cache::remember(
            "menu_outlet_{$outletId}",
            60,
            fn () => Product::query()
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->with(['category:id,name', 'station:id,name'])
                ->orderBy('category_id')
                ->orderBy('name')
                ->get(['id', 'category_id', 'station_id', 'name', 'description', 'price', 'image'])
        );

        return response()->json([
            'success' => true,
            'data' => [
                'table' => $table->only(['id', 'name', 'code']),
                'products' => $products,
            ]
        ], 200);
    }

    /**
     * GET /products
     * List produk dengan filter
     *
     * SECURITY:
     * - Manager: hanya produk di outlet miliknya
     * - Karyawan: hanya produk di outlet miliknya
     * - Developer: semua produk
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Product::query();

        // SECURITY: Filter by outlet
        if ($user->isManager()) {
            $outletIds = $user->ownedOutlets()->pluck('id')->toArray();
            $query->whereIn('outlet_id', $outletIds);
        } elseif ($user->isKaryawan()) {
            $query->where('outlet_id', $user->outlet_id);
        }
        // Developer bisa lihat semua

        // Optional filters
        if ($request->filled('outlet_id')) {
            if (!$user->canAccessOutlet($request->outlet_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden',
                ], 403);
            }
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('station_id')) {
            $query->where('station_id', $request->station_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $limit = min($request->input('limit', 20), 100);

        $products = $query->with(['category:id,name', 'outlet:id,name', 'station:id,name'])
            ->latest()
            ->paginate($limit);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * POST /products
     * Create produk baru
     *
     * SECURITY:
     * - Manager: create di outlet miliknya saja
     * - Karyawan: tidak bisa create
     * - Developer: bisa di outlet manapun
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        // SECURITY: Hanya manager dan developer
        if (!$user->isManager() && !$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Hanya manager dan developer',
            ], 403);
        }

        $validated = $request->validate([
            'outlet_id' => $user->isDeveloper() ? 'required|exists:outlets,id' : 'nullable',
            'category_id' => 'required|exists:categories,id',
            'station_id' => 'nullable|exists:stations,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|integer|min:0',
            'cost_price' => 'required|integer|min:0',
            'stock' => 'required|integer|min:0',
            'image' => 'nullable|image|max:5120',
            'is_active' => 'boolean',
        ]);

        // SECURITY: Manager assign ke outlet miliknya
        if ($user->isManager()) {
            $outletId = $user->ownedOutlets()->first()?->id;
            if (!$outletId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Manager tidak memiliki outlet',
                ], 422);
            }
            $validated['outlet_id'] = $outletId;
        }

        // SECURITY: Validasi category belongs to same outlet
        $category = \App\Models\Category::findOrFail($validated['category_id']);
        if ($category->outlet_id != $validated['outlet_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Category tidak sesuai dengan outlet',
            ], 422);
        }

        // SECURITY: Validasi station belongs to same outlet (jika ada)
        if ($validated['station_id']) {
            $station = \App\Models\Station::findOrFail($validated['station_id']);
            if ($station->outlet_id != $validated['outlet_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Station tidak sesuai dengan outlet',
                ], 422);
            }
        }

        // SECURITY: Check user dapat akses outlet
        if (!$user->canAccessOutlet($validated['outlet_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Tidak dapat akses outlet ini',
            ], 403);
        }

        // Upload image jika ada
        if ($request->hasFile('image')) {
            $validated['image'] = $this->uploadImage($request->file('image'), $validated['name']);
        }

        $validated['is_active'] = $validated['is_active'] ?? true;

        $product = Product::create($validated);

        // Clear cache
        Cache::forget("menu_outlet_{$validated['outlet_id']}");

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dibuat',
            'data' => $product->load('category', 'outlet', 'station'),
        ], 201);
    }

    /**
     * GET /products/{product}
     * Show detail produk
     */
    public function show(Request $request, Product $product)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$product->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $product->load('category', 'outlet', 'station'),
        ]);
    }

    /**
     * PUT /products/{product}
     * Update produk
     */
    public function update(Request $request, Product $product)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$product->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // SECURITY: Hanya manager dan developer
        if (!$user->isManager() && !$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'category_id' => 'sometimes|required|exists:categories,id',
            'station_id' => 'sometimes|nullable|exists:stations,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'price' => 'sometimes|required|integer|min:0',
            'cost_price' => 'sometimes|required|integer|min:0',
            'stock' => 'sometimes|required|integer|min:0',
            'image' => 'sometimes|nullable|image|max:5120',
            'is_active' => 'sometimes|boolean',
        ]);

        // SECURITY: Validasi category belongs to same outlet
        if (isset($validated['category_id'])) {
            $category = \App\Models\Category::findOrFail($validated['category_id']);
            if ($category->outlet_id != $product->outlet_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category tidak sesuai dengan outlet',
                ], 422);
            }
        }

        // SECURITY: Validasi station belongs to same outlet
        if (isset($validated['station_id']) && $validated['station_id']) {
            $station = \App\Models\Station::findOrFail($validated['station_id']);
            if ($station->outlet_id != $product->outlet_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Station tidak sesuai dengan outlet',
                ], 422);
            }
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $this->uploadImage($request->file('image'), $validated['name'] ?? $product->name);
        }

        $product->update($validated);

        // Clear cache
        Cache::forget("menu_outlet_{$product->outlet_id}");

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diperbarui',
            'data' => $product->load('category', 'outlet', 'station'),
        ]);
    }

    /**
     * DELETE /products/{product}
     * Delete produk
     */
    public function destroy(Request $request, Product $product)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$product->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // SECURITY: Hanya manager dan developer
        if (!$user->isManager() && !$user->isDeveloper()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // Delete image if exists
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        $outletId = $product->outlet_id;
        $product->delete();

        // Clear cache
        Cache::forget("menu_outlet_{$outletId}");

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus',
        ]);
    }

    /**
     * HELPER: Upload image
     */
    private function uploadImage($file, string $name): string
    {
        $filename = Str::slug($name) . '-' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        return $file->storeAs('products', $filename, 'public');
    }
}
