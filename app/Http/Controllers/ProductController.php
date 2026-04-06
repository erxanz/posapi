<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Table;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * PUBLIC MENU (QR) - OPTIMIZED
     */
    public function publicMenu($outletId, $tableId)
    {
        $table = Table::query()
            ->select(['id', 'name', 'outlet_id', 'is_active'])
            ->where('id', $tableId)
            ->where('outlet_id', $outletId)
            ->firstOrFail();

        $products = Cache::remember(
            "menu_outlet_{$outletId}",
            60,
            fn () => Product::query()
                ->select([
                    'id',
                    'category_id',
                    'station_id',
                    'name',
                    'description',
                    'price',
                    'stock',
                    'image',
                    'is_active',
                ])
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->where('stock', '>', 0) // hanya tampilkan yang ada stok
                ->with([
                    'category:id,name'
                ])
                ->orderBy('name')
                ->get()
        );

        return response()->json([
            'table' => $table,
            'products' => $products
        ], 200);
    }

    /**
     * LIST PRODUCT (NO N+1)
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Product::query()
            ->where('outlet_id', $user->outlet_id)
            ->select([
                'id',
                'category_id',
                'station_id',
                'name',
                'description',
                'price',
                'cost_price',
                'stock',
                'image',
                'is_active',
            ])
            ->with([
                'category:id,name'
            ])
            ->latest();

        // FILTER CATEGORY (AMAN)
        if ($request->filled('category_id')) {
            $query->whereHas('category', function ($q) use ($request, $user) {
                $q->where('id', $request->category_id)
                  ->where('outlet_id', $user->outlet_id);
            });
        }

        // SEARCH (multi keyword, fleksibel)
        if ($request->filled('search')) {
            $keywords = array_filter(explode(' ', trim($request->search)));

            $query->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->where('name', 'like', "%{$word}%");
                }
            });
        }

        $limit = min($request->limit ?? 10, 100);

        return response()->json([
            'data' => $query->paginate($limit)
        ]);
    }

    /**
     * STORE PRODUCT
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        // VALIDASI AMAN (category sesuai outlet)
        $request->validate([
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where(function ($q) use ($user) {
                    $q->where('outlet_id', $user->outlet_id);
                })
            ],
            'station_id' => [
                'nullable',
                Rule::exists('stations', 'id')->where(function ($q) use ($user) {
                    $q->where('outlet_id', $user->outlet_id);
                })
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|integer|min:0',
            'cost_price' => 'required|integer|min:0',
            'stock' => 'required|integer|min:0',
            'image' => 'nullable|image|max:2048',
            'is_active' => 'boolean'
        ]);

        $data = $request->only([
            'category_id',
            'station_id',
            'name',
            'description',
            'price',
            'cost_price',
            'stock',
            'is_active'
        ]);

        // UPLOAD IMAGE
        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $data['outlet_id'] = $user->outlet_id;

        $product = Product::create($data);

        // CLEAR CACHE
        Cache::forget("menu_outlet_{$user->outlet_id}");

        return response()->json([
            'data' => $product->load('category:id,name')
        ], 201);
    }

    /**
     * SHOW PRODUCT
     */
    public function show(Product $product)
    {
        $this->authorizeProduct($product);

        return response()->json([
            'data' => $product->load('category:id,name')
        ], 200);
    }

    /**
     * UPDATE PRODUCT
     */
    public function update(Request $request, Product $product)
    {
        $user = auth()->user();

        $this->authorizeProduct($product);

        $request->validate([
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where(function ($q) use ($user) {
                    $q->where('outlet_id', $user->outlet_id);
                })
            ],
            'station_id' => [
                'nullable',
                Rule::exists('stations', 'id')->where(function ($q) use ($user) {
                    $q->where('outlet_id', $user->outlet_id);
                })
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|integer|min:0',
            'cost_price' => 'required|integer|min:0',
            'stock' => 'required|integer|min:0',
            'image' => 'nullable|image|max:2048',
            'is_active' => 'boolean'
        ]);

        $data = $request->only([
            'category_id',
            'station_id',
            'name',
            'description',
            'price',
            'cost_price',
            'stock',
            'is_active'
        ]);

        // UPDATE IMAGE
        if ($request->hasFile('image')) {

            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }

            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $product->update($data);

        // CLEAR CACHE
        Cache::forget("menu_outlet_{$user->outlet_id}");

        return response()->json([
            'data' => $product->load('category:id,name')
        ], 200);
    }

    /**
     * DELETE PRODUCT
     */
    public function destroy(Product $product)
    {
        $user = auth()->user();

        $this->authorizeProduct($product);

        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        // CLEAR CACHE
        Cache::forget("menu_outlet_{$user->outlet_id}");

        return response()->json([
            'message' => 'Product deleted successfully'
        ], 200);
    }

    /**
     * HELPER AUTH
     */
    private function authorizeProduct(Product $product): void
    {
        if ($product->outlet_id !== auth()->user()->outlet_id) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * HELPER UPLOAD IMAGE
     */
    private function uploadImage($file, $name): string
    {
        $filename = Str::slug($name) . '-' . Str::random(6) . '.' . $file->getClientOriginalExtension();

        return $file->storeAs('products', $filename, 'public');
    }
}
