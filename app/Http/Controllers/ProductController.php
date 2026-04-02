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
     * PUBLIC MENU (QR)
     */
    public function publicMenu($outletId, $tableId)
    {
        // validasi table
        $table = Table::where('id', $tableId)
            ->where('outlet_id', $outletId)
            ->firstOrFail();

        // IMPORTANT: bypass global scope
        $products = Product::withoutGlobalScopes()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->with('category')
            ->get();

        return response()->json([
            'table' => $table,
            'products' => $products
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::with('category')->latest();

        // ❌ HAPUS: sudah di-handle global scope

        // Filter category (AMAN: pastikan category milik outlet user)
        if ($request->filled('category_id')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('id', $request->category_id)
                  ->where('outlet_id', auth()->user()->outlet_id);
            });
        }

        // Search
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
     * Store a newly created resource
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:2048'
        ]);

        // VALIDASI: category harus milik outlet user
        $category = Category::where('id', $request->category_id)
            ->where('outlet_id', $user->outlet_id)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Category tidak valid'], 422);
        }

        $data = $request->except('image');

        // upload image
        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $data['outlet_id'] = $user->outlet_id;

        $product = Product::create($data);

        return response()->json($product->load('category'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        return response()->json(
            $product->load('category')
        );
    }

    /**
     * Update product
     */
    public function update(Request $request, Product $product)
    {
        $user = auth()->user();

        if ($product->outlet_id !== $user->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:2048'
        ]);

        // VALIDASI category
        $category = Category::where('id', $request->category_id)
            ->where('outlet_id', $user->outlet_id)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Category tidak valid'], 422);
        }

        $data = $request->except('image');

        // upload image baru
        if ($request->hasFile('image')) {

            // HAPUS image lama
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $product->update($data);

        return response()->json($product->load('category'), 200);
    }

    /**
     * Delete product
     */
    public function destroy(Product $product)
    {
        $user = auth()->user();

        if ($product->outlet_id !== $user->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ], 200);
    }

    /**
     * Helper upload image
     */
    private function uploadImage($file, $name)
    {
        $filename = Str::slug($name) . '-' . Str::random(6) . '.' . $file->getClientOriginalExtension();

        return $file->storeAs('products', $filename, 'public');
    }
}
