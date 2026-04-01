<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::with('category')->latest();

        // Filter by outlet
        $query->where('outlet_id', auth()->user()->outlet_id);

        // Filter category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Search by name (clean keyword)
        if ($request->filled('search')) {
            $keywords = array_filter(explode(' ', trim($request->search)));

            $query->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->where('name', 'like', "%{$word}%");
                }
            });
        }

        // Only active product
        $query->where('is_active', true);

        return response()->json(
            $query->paginate($request->limit ?? 10)
        );
    }

    /**
     * Store a newly created resource in storage.
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
            'price' => 'required|integer',
            'description' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        $data = $request->all();

        // upload image if exists
        if ($request->hasFile('image')) {
            $file = $request->file('image');

            // ambil nama product → slug
            $name = Str::slug($request->name);

            // random string
            $random = Str::random(6);

            // ambil extension asli
            $extension = $file->getClientOriginalExtension();

            // gabung jadi nama file
            $filename = $name . '-' . $random . '.' . $extension;

            // simpan
            $data['image'] = $file->storeAs('products', $filename, 'public');
        } else {
            $data['image'] = null;
        }

        // WAJIB
        $data['outlet_id'] = $user->outlet_id;

        $product = Product::create($data);

        return response()->json($product->load('category'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        if ($product->outlet_id !== auth()->user()->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            $product->load('category')
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'required|integer',
            'description' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        $data = $request->all();

        // upload image if exists
        if ($request->hasFile('image')) {
            $file = $request->file('image');

            // ambil nama product → slug
            $name = Str::slug($request->name);

            // random string
            $random = Str::random(6);

            // ambil extension asli
            $extension = $file->getClientOriginalExtension();

            // gabung jadi nama file
            $filename = $name . '-' . $random . '.' . $extension;

            // simpan
            $data['image'] = $file->storeAs('products', $filename, 'public');
        }

        $product->update($data);

        return response()->json($product->load('category'), 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        // delete image if exists
        if ($product->image) {
            // delete old image
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ], 200);
    }
}
