<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Category;
use App\Models\Table;

class ProductController extends Controller
{
    /**
     * PUBLIC MENU (QR) - OPTIMIZED
     */
    public function publicMenu($outletId, $tableId)
    {
        $table = Table::query()
            ->select(['id', 'name', 'outlet_id', 'status'])
            ->where('id', $tableId)
            ->where('outlet_id', $outletId)
            ->firstOrFail();

        $products = Product::withoutGlobalScopes()
            ->select([
                'id',
                'category_id',
                'station_id',
                'name',
                'price',
                'description',
                'cost_price',
                'stock',
                'image',
                'is_active',
            ])
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->with([
                'category:id,name'
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'table' => $table,
            'products' => $products
        ]);
    }

    /**
     * LIST PRODUCT (NO N+1)
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Product::query()
            ->select([
                'id',
                'category_id',
                'station_id',
                'name',
                'price',
                'description',
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

        // SEARCH (multi keyword)
        if ($request->filled('search')) {
            $keywords = array_filter(explode(' ', trim($request->search)));

            $query->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->where('name', 'like', "%{$word}%");
                }
            });
        }

        return response()->json(
            $query->paginate($request->limit ?? 10)
        );
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

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'station_id' => 'nullable|exists:stations,id',
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'cost_price' => 'required|integer|min:0',
            'stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:2048'
        ]);

        // VALIDASI CATEGORY
        $category = Category::where('id', $request->category_id)
            ->where('outlet_id', $user->outlet_id)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Category tidak valid'], 422);
        }

        $data = $request->only([
            'category_id',
            'station_id',
            'name',
            'price',
            'description',
            'cost_price',
            'stock',
            'image',
            'is_active'
        ]);

        // UPLOAD IMAGE
        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $data['outlet_id'] = $user->outlet_id;

        $product = Product::create($data);

        return response()->json(
            $product->load('category:id,name'),
            201
        );
    }

    /**
     * SHOW PRODUCT
     */
    public function show(Product $product)
    {
        $this->authorizeProduct($product);

        return response()->json(
            $product->load('category:id,name')
        );
    }

    /**
     * UPDATE PRODUCT
     */
    public function update(Request $request, Product $product)
    {
        $user = auth()->user();

        $this->authorizeProduct($product);

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'station_id' => 'nullable|exists:stations,id',
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'cost_price' => 'required|integer|min:0',
            'stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:2048'
        ]);

        // VALIDASI CATEGORY
        $category = Category::where('id', $request->category_id)
            ->where('outlet_id', $user->outlet_id)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Category tidak valid'], 422);
        }

        $data = $request->only([
            'category_id',
            'station_id',
            'name',
            'price',
            'description',
            'cost_price',
            'stock',
            'image',
            'is_active',
        ]);

        // UPDATE IMAGE
        if ($request->hasFile('image')) {

            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $product->update($data);

        return response()->json(
            $product->load('category:id,name')
        );
    }

    /**
     * DELETE PRODUCT
     */
    public function destroy(Product $product)
    {
        $this->authorizeProduct($product);

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ]);
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
